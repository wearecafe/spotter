<?php

namespace App\Services;

use App\Models\WorkoutExercise;
use App\Support\Load;

/**
 * Progression rules: whether a client earned the next step (planned vs actual), and what the step is.
 */
class Progression
{
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

            $low = min((int) $m[1] + $step, (int) $m[2]);

            return $low >= (int) $m[2] ? $m[2] : $low.'-'.$m[2];
        }

        if (preg_match('/^\s*(\d+)\s*$/', $reps, $m)) {
            return (string) ((int) $m[1] + $step);
        }

        return $reps;
    }

    /**
     * Step a prescription forward once, assuming the client hits it (used to plan future weeks).
     *
     * @return array{load: string|null, reps: string|null, changed: array<int, string>}
     */
    public function advance(?string $load, ?string $reps, string $rule, ?string $step): array
    {
        if ($rule === 'load' && Load::parse($load)['value'] !== null) {
            return ['load' => Load::add((string) $load, $step ?: Load::defaultStep($load)), 'reps' => $reps, 'changed' => ['load']];
        }

        if ($rule === 'reps' && $reps !== null) {
            $next = $this->addReps($reps, (int) ($step ?: 1));

            return ['load' => $load, 'reps' => $next, 'changed' => $next === $reps ? [] : ['reps']];
        }

        return ['load' => $load, 'reps' => $reps, 'changed' => []];
    }
}
