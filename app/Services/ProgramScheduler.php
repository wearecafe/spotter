<?php

namespace App\Services;

use App\Models\Program;
use App\Models\ProgramDay;
use App\Models\ProgramExercise;
use App\Models\User;
use App\Models\Workout;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a program blueprint into sessions on the client's calendar, every planned week.
 *
 * Sessions keep following the blueprint until the client starts them or the coach edits them
 * by hand. Loads and reps for future weeks are planned from the rules and re-planned from what
 * the client actually logged each time a session is completed.
 */
class ProgramScheduler
{
    /** Default weekdays (ISO, Monday = 1) for a given number of training days a week. */
    public const DEFAULT_WEEKDAYS = [
        1 => [1], 2 => [1, 4], 3 => [1, 3, 5], 4 => [1, 2, 4, 5], 5 => [1, 2, 3, 4, 5], 6 => [1, 2, 3, 4, 5, 6], 7 => [1, 2, 3, 4, 5, 6, 7],
    ];

    public function __construct(protected Progression $progression) {}

    /**
     * Start a program with empty days on sensible weekdays, scheduled for every week.
     */
    public function create(User $client, string $title, CarbonInterface|string $startsOn, int $weeks, int $daysPerWeek): Program
    {
        return DB::transaction(function () use ($client, $title, $startsOn, $weeks, $daysPerWeek) {
            $program = Program::create([
                'coach_id' => $client->coach_id,
                'client_id' => $client->id,
                'title' => $title,
                'starts_on' => $startsOn,
                'planned_weeks' => $weeks,
            ]);

            foreach (self::DEFAULT_WEEKDAYS[max(1, min($daysPerWeek, 7))] as $i => $weekday) {
                $program->days()->create(['position' => $i + 1, 'title' => __('Day :label', ['label' => chr(65 + $i)]), 'weekday' => $weekday]);
            }

            $this->sync($program);

            return $program;
        });
    }

    public function sync(Program $program): void
    {
        DB::transaction(function () use ($program) {
            foreach ($program->days()->get() as $day) {
                $this->syncDay($day->setRelation('program', $program));
            }
        });
    }

    /**
     * Bring every week's session for one day in line with the blueprint.
     */
    public function syncDay(ProgramDay $day): void
    {
        DB::transaction(function () use ($day) {
            $program = $day->program;
            $blueprint = $day->exercises()->get();
            $sessions = Workout::query()->where('program_day_id', $day->id)->with('items')->get()->keyBy('week');
            $plan = $this->plan($program, $day, $blueprint, $sessions);
            $skipped = $this->skippedWeeks($day);

            for ($week = 1; $week <= $program->planned_weeks; $week++) {
                $date = $program->dateFor($week, $day->weekday);
                $session = $sessions->get($week);

                // Before the program starts, or a week the coach skipped: no session.
                if ($date->lt($program->starts_on) || in_array($week, $skipped, true)) {
                    if ($session?->followsProgram()) {
                        $session->delete();
                    }

                    continue;
                }

                if ($session && ! $session->followsProgram()) {
                    continue;
                }

                $session ??= new Workout(['program_id' => $program->id, 'program_day_id' => $day->id, 'week' => $week]);
                $session->fill([
                    'coach_id' => $program->client?->coach_id ?? $program->coach_id,
                    'client_id' => $program->client_id,
                    'day' => $day->position,
                    'title' => $day->title,
                    'instructions' => $day->notes,
                    'scheduled_on' => $date,
                ])->save();

                $this->syncItems($session, $blueprint, $plan[$week] ?? []);
            }

            // Weeks dropped from the end of the program.
            $sessions->filter(fn (Workout $s) => $s->week > $program->planned_weeks && $s->followsProgram())->each->delete();
        });
    }

    /**
     * @param  Collection<int, ProgramExercise>  $blueprint
     * @param  array<int, array<string, mixed>>  $planned  keyed by program_exercise_id
     */
    protected function syncItems(Workout $session, Collection $blueprint, array $planned): void
    {
        $existing = $session->items()->get()->keyBy('program_exercise_id');

        foreach ($blueprint as $row) {
            $attributes = ($planned[$row->id] ?? $row->only(ProgramExercise::PRESCRIPTION_FIELDS)) + ['program_exercise_id' => $row->id];

            if ($item = $existing->get($row->id)) {
                $item->fill($attributes)->save();
            } else {
                $session->items()->create($attributes);
            }
        }

        // Exercises removed from the blueprint (and anything not from it) leave the session.
        $session->items()->where(fn ($q) => $q->whereNull('program_exercise_id')->orWhereNotIn('program_exercise_id', $blueprint->pluck('id')))->delete();
    }

    /**
     * Plan every week's load and reps for each blueprint exercise.
     *
     * Week by week: if last week's session was completed, the rule is checked against what the
     * client logged (progress or hold). If it was missed, hold. If it hasn't happened yet, plan
     * the next step assuming it goes to plan.
     *
     * @param  Collection<int, ProgramExercise>  $blueprint
     * @param  Collection<int, Workout>  $sessions  keyed by week
     * @return array<int, array<int, array<string, mixed>>> week => program_exercise_id => attributes
     */
    public function plan(Program $program, ProgramDay $day, Collection $blueprint, Collection $sessions): array
    {
        $plan = [];

        foreach ($blueprint as $row) {
            $load = $row->load;
            $reps = $row->reps;

            for ($week = 1; $week <= $program->planned_weeks; $week++) {
                $changed = [];
                $reason = null;

                if ($week > $row->base_week) {
                    $previous = $sessions->get($week - 1);
                    $previousItem = $previous?->items->firstWhere('program_exercise_id', $row->id);

                    if ($previous && $previous->isCompleted() && $previousItem) {
                        // Judge last week against what was actually planned for it, with today's rule.
                        $previousItem->progression = $row->progression;
                        $previousItem->progression_step = $row->progression_step;
                        $next = $this->progression->nextPrescription($previousItem);
                        [$changed, $reason] = [$next['changed_fields'], $next['change_reason']];
                        [$load, $reps] = [$next['load'], $next['reps']];
                    } elseif ($previous && ! $previous->isCompleted() && $previous->scheduled_on?->lt(today())) {
                        $reason = in_array($row->progression, ['load', 'reps'], true) ? __('Held: week :n wasn’t completed', ['n' => $week - 1]) : null;
                    } else {
                        $step = $this->progression->advance($load, $reps, $row->progression, $row->progression_step);
                        [$load, $reps, $changed] = [$step['load'], $step['reps'], $step['changed']];
                        $reason = $changed ? __('Planned: progresses if week :n goes to plan', ['n' => $week - 1]) : null;
                    }
                }

                // Rep ranges stop at the top of the coach's range ("8-10" climbs to 10, then holds).
                $capped = $this->capReps($reps, $row->reps);
                if ($capped !== $reps) {
                    $reps = $capped;
                    $changed = array_values(array_diff($changed, ['reps']));
                    $reason = $row->progression === 'reps' ? __('Top of the rep range: consider adding load') : $reason;
                }

                $plan[$week][$row->id] = array_merge($row->only(ProgramExercise::PRESCRIPTION_FIELDS), [
                    'load' => $load,
                    'reps' => $reps,
                    'changed_fields' => $changed,
                    'change_reason' => $reason,
                ]);
            }
        }

        return $plan;
    }

    protected function capReps(?string $reps, ?string $blueprint): ?string
    {
        if ($reps === null || ! preg_match('/^\s*\d+\s*-\s*(\d+)\s*$/', (string) $blueprint, $range)) {
            return $reps;
        }

        return preg_match('/^\s*(\d+)\s*$/', $reps, $n) && (int) $n[1] > (int) $range[1] ? $range[1] : $reps;
    }

    /**
     * The first week of a day that's still open for planning: not started, not edited by hand.
     */
    public function firstOpenWeek(ProgramDay $day): int
    {
        $locked = Workout::query()->where('program_day_id', $day->id)->with('items')->get()
            ->reject(fn (Workout $s) => $s->followsProgram())
            ->max('week');

        return min(($locked ?? 0) + 1, 255);
    }

    /**
     * Skip one week of a day (the coach removed that session). Sync won't recreate it.
     */
    public function skip(Workout $session): void
    {
        if ($day = $session->programDay) {
            $day->update(['skipped_weeks' => collect($this->skippedWeeks($day))->push($session->week)->unique()->sort()->values()->all()]);
        }

        $session->delete();
    }

    public function unskip(ProgramDay $day, int $week): void
    {
        $weeks = array_values(array_diff($this->skippedWeeks($day), [$week]));
        $day->update(['skipped_weeks' => $weeks ?: null]);

        $this->syncDay($day);
    }

    /**
     * @return array<int, int>
     */
    public function skippedWeeks(ProgramDay $day): array
    {
        return array_map('intval', $day->skipped_weeks ?? []);
    }

    /**
     * Mark a session as edited by hand so program edits stop changing it.
     */
    public function customize(Workout $session): void
    {
        if ($session->program_day_id && $session->customized_at === null) {
            $session->forceFill(['customized_at' => now()])->save();
        }
    }

    /**
     * Put a hand-edited session back on the program.
     */
    public function reset(Workout $session): void
    {
        if (! $session->program_day_id || $session->isCompleted()) {
            return;
        }

        $session->forceFill(['customized_at' => null])->save();
        $session->items()->whereNull('program_exercise_id')->delete();
        $session->items()->update(['logged_sets' => null]);

        $this->syncDay($session->programDay);
    }

    /**
     * Re-plan after the client finishes (or reopens) a session.
     */
    public function sessionChanged(Workout $session): void
    {
        if ($session->programDay) {
            $this->syncDay($session->programDay);
        }
    }
}
