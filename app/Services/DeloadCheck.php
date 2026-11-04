<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkoutExercise;
use App\Support\Load;
use Illuminate\Support\Collection;

/**
 * Spots a lift that has felt harder than planned two sessions running (RPE over the target)
 * and suggests lightening the next session of it. Only ever a suggestion: the coach approves
 * it from the inbox, and it changes that one upcoming session.
 */
class DeloadCheck
{
    /** How much lighter the suggested session is. */
    public const LOAD_STEP = '-10%';

    /**
     * Exercises in a just-completed session that are due a deload suggestion.
     *
     * @param  Collection<int, WorkoutExercise>  $items  items of a completed workout
     * @return Collection<int, WorkoutExercise>
     */
    public function due(User $client, Collection $items): Collection
    {
        return $items->filter(fn (WorkoutExercise $item) => $this->overTwiceRunning($client, $item))->values();
    }

    /**
     * The last two completed sessions of this exercise (this one included) both had a set
     * logged above the target RPE.
     */
    public function overTwiceRunning(User $client, WorkoutExercise $item): bool
    {
        if (Load::high($item->target_rpe) === null || ! $this->overTarget($item)) {
            return false;
        }

        $previous = WorkoutExercise::query()
            ->where('exercise_id', $item->exercise_id)
            ->where('id', '!=', $item->id)
            ->whereHas('workout', fn ($q) => $q->where('client_id', $client->id)->whereNotNull('completed_at')
                ->where('completed_at', '<=', $item->workout->completed_at ?? now()))
            ->with('workout:id,completed_at')
            ->get()
            // Only sessions where the client logged RPE tell us anything.
            ->filter(fn (WorkoutExercise $w) => collect($w->logged_sets ?? [])->contains(fn ($s) => ($s['done'] ?? false) && filled($s['rpe'] ?? null)))
            ->sortByDesc(fn (WorkoutExercise $w) => $w->workout->completed_at)
            ->first();

        return $previous !== null && $this->overTarget($previous);
    }

    public function overTarget(WorkoutExercise $item): bool
    {
        $max = Load::high($item->target_rpe);

        return $max !== null && collect($item->logged_sets ?? [])
            ->contains(fn ($s) => ($s['done'] ?? false) && filled($s['rpe'] ?? null) && (float) $s['rpe'] > $max);
    }

    /**
     * The change to suggest: 10% off the next session's load, or a set fewer when there's no load.
     * In the shape the inbox's Apply button takes.
     *
     * @return array{workout_exercise_id: int, exercise: string, field: string, from: string, to: string, reason: string}|null
     */
    public function suggestion(User $client, WorkoutExercise $after): ?array
    {
        $next = WorkoutExercise::query()
            ->where('exercise_id', $after->exercise_id)
            ->whereHas('workout', fn ($q) => $q->where('client_id', $client->id)->whereNull('completed_at')->where('scheduled_on', '>=', today()))
            ->with(['workout', 'exercise'])
            ->get()
            ->reject(fn (WorkoutExercise $w) => $w->workout->isStarted())
            ->sortBy(fn (WorkoutExercise $w) => $w->workout->scheduled_on)
            ->first();

        if ($next === null) {
            return null;
        }

        $reason = __('RPE above the :target target two sessions running: lighten the next one.', ['target' => $after->target_rpe]);

        if ($next->load !== null && Load::parse($next->load)['value'] !== null) {
            $lighter = Load::add($next->load, self::LOAD_STEP);

            if ($lighter !== $next->load) {
                return ['workout_exercise_id' => $next->id, 'exercise' => $next->exercise->name, 'field' => 'load', 'from' => $next->load, 'to' => $lighter, 'reason' => $reason];
            }
        }

        if ($next->sets > 1) {
            return ['workout_exercise_id' => $next->id, 'exercise' => $next->exercise->name, 'field' => 'sets', 'from' => (string) $next->sets, 'to' => (string) ($next->sets - 1), 'reason' => $reason];
        }

        return null;
    }
}
