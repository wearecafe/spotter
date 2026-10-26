<?php

namespace App\Services;

use App\Models\Program;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Support\Load;
use Illuminate\Support\Facades\DB;

/**
 * Builds the next week of a program from the last one, applying each row's rule
 * against what the client actually did (planned vs actual).
 */
class Progression
{
    public function generateNextWeek(Program $program): int
    {
        return DB::transaction(function () use ($program) {
            $last = $program->lastWeek();
            $next = $last + 1;

            $lastWeek = $program->workouts()->where('week', $last)->with('items')->get();

            foreach ($lastWeek as $workout) {
                $copy = $workout->replicate(['scheduled_on', 'completed_at', 'client_feedback', 'reported_at', 'week'])->unsetRelations();
                $copy->week = $next;
                $copy->scheduled_on = $workout->scheduled_on?->addWeek();
                $copy->save();

                foreach ($workout->items as $item) {
                    $copy->items()->create($this->nextPrescription($item));
                }
            }

            return $next;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function nextPrescription(WorkoutExercise $item): array
    {
        $attributes = $item->only(WorkoutExercise::PRESCRIPTION_FIELDS);
        $attributes['changed_fields'] = [];
        $attributes['change_reason'] = null;

        if ($item->progression === 'none') {
            return $attributes;
        }

        if ($item->progression === 'hold') {
            $attributes['change_reason'] = __('Held by rule');

            return $attributes;
        }

        [$earned, $why] = $this->earned($item);

        if (! $earned) {
            $attributes['change_reason'] = __('Held: :why', ['why' => $why]);

            return $attributes;
        }

        if ($item->progression === 'load' && Load::parse($item->load)['value'] !== null) {
            $step = $item->progression_step ?: Load::defaultStep($item->load);
            $attributes['load'] = Load::add((string) $item->load, $step);
            $attributes['changed_fields'] = ['load'];
            $attributes['change_reason'] = __('All sets hit at or under target RPE: +:step', ['step' => $step]);
        }

        if ($item->progression === 'reps' && $item->reps !== null) {
            $attributes['reps'] = $this->addReps($item->reps, (int) ($item->progression_step ?: 1));
            $attributes['changed_fields'] = $attributes['reps'] === $item->reps ? [] : ['reps'];
            $attributes['change_reason'] = $attributes['changed_fields'] === []
                ? __('Top of rep range reached; consider adding load')
                : __('All sets hit: +:n rep', ['n' => (int) ($item->progression_step ?: 1)]);
        }

        return $attributes;
    }

    /**
     * Did the client earn progression? Every set done, reps and load at least the target, RPE no higher than target.
     * Blank reps or load on a completed set count as "as prescribed", since that's what the logger shows as the placeholder.
     *
     * @return array{0: bool, 1: string}
     */
    public function earned(WorkoutExercise $item): array
    {
        $sets = collect($item->logged_sets ?? []);

        if ($sets->isEmpty()) {
            return [false, __('not logged yet')];
        }

        if ($sets->count() < $item->sets || $sets->contains(fn ($s) => ! ($s['done'] ?? false))) {
            return [false, __('not every set was completed')];
        }

        $minReps = Load::low($item->reps);
        if ($minReps !== null && $sets->contains(fn ($s) => filled($s['reps'] ?? null) && (float) $s['reps'] < $minReps)) {
            return [false, __('reps came in under target')];
        }

        // A blank logged load means the client used the prescribed one (it's the placeholder in the logger).
        $prescribedKg = Load::toKg($item->load);
        if ($prescribedKg !== null && $sets->contains(fn ($s) => filled($s['load'] ?? null) && ($kg = Load::toKg($s['load'])) !== null && $kg < $prescribedKg - 0.01)) {
            return [false, __('load came in under target')];
        }

        $maxRpe = Load::high($item->target_rpe);
        if ($maxRpe !== null && $sets->contains(fn ($s) => filled($s['rpe'] ?? null) && (float) $s['rpe'] > $maxRpe)) {
            return [false, __('RPE was above target')];
        }

        return [true, ''];
    }

    protected function addReps(string $reps, int $step): string
    {
        if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $reps, $m)) {
            // Climb the bottom of the range; once it meets the top the range is "done" and stays put.
            if ((int) $m[1] >= (int) $m[2]) {
                return $reps;
            }

            return min((int) $m[1] + $step, (int) $m[2]).'-'.$m[2];
        }

        if (preg_match('/^\s*(\d+)\s*$/', $reps, $m)) {
            return (string) ((int) $m[1] + $step);
        }

        return $reps;
    }

    /**
     * Create an empty program with its first week of days for a client.
     */
    public function start(Program $program, int $daysPerWeek): void
    {
        $labels = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        $gap = intdiv(7, max($daysPerWeek, 1));

        for ($day = 1; $day <= $daysPerWeek; $day++) {
            Workout::create([
                'coach_id' => $program->coach_id,
                'client_id' => $program->client_id,
                'program_id' => $program->id,
                'week' => 1,
                'day' => $day,
                'title' => __('Day :label', ['label' => $labels[$day - 1] ?? $day]),
                'scheduled_on' => $program->starts_on->addDays(($day - 1) * $gap),
            ]);
        }
    }
}
