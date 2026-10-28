<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A trainer running a session in the gym and logging it on the client's behalf.
 */
class TrainerSession
{
    public function __construct(protected ProgramScheduler $scheduler, protected InboxFeed $feed) {}

    /**
     * The client's session for today: an unfinished one first, otherwise one already done today.
     */
    public function today(User $client): ?Workout
    {
        return $client->assignedWorkouts()
            ->whereDate('scheduled_on', today())
            ->orderByRaw('completed_at is not null')
            ->orderBy('id')
            ->first();
    }

    /**
     * The next unfinished session after today, for pulling forward.
     */
    public function next(User $client): ?Workout
    {
        return $client->assignedWorkouts()
            ->whereNull('completed_at')
            ->whereDate('scheduled_on', '>', today())
            ->orderBy('scheduled_on')
            ->orderBy('id')
            ->first();
    }

    /**
     * The session to run now: today's, or the next one moved to today.
     * Null when the client has nothing scheduled.
     */
    public function start(User $client): ?Workout
    {
        if ($session = $this->today($client)) {
            return $session;
        }

        $next = $this->next($client);

        if ($next === null) {
            return null;
        }

        return DB::transaction(function () use ($next) {
            // A program session moved to another day keeps its plan but stops following the blueprint's weekday.
            $this->scheduler->customize($next);
            $next->update(['scheduled_on' => today()]);

            return $next;
        });
    }

    /**
     * Save one exercise's sets as the trainer enters them.
     *
     * @param  array<int, array<string, mixed>>  $sets
     */
    public function saveSets(Workout $session, WorkoutExercise $item, array $sets, User $trainer): void
    {
        $item->update(['logged_sets' => $this->normalize($sets)]);

        if ($session->logged_by_id !== $trainer->id) {
            $session->update(['logged_by_id' => $trainer->id]);
        }
    }

    public function saveNotes(Workout $session, ?string $notes, User $trainer): void
    {
        $session->update(['session_notes' => filled($notes) ? $notes : null, 'logged_by_id' => $trainer->id]);
    }

    public function complete(Workout $session, User $trainer): void
    {
        $session->update(['completed_at' => now(), 'logged_by_id' => $trainer->id]);
        $this->feed->workoutCompleted($session->fresh());

        // Re-plan the coming weeks of the program from what was actually lifted.
        $this->scheduler->sessionChanged($session);
    }

    public function reopen(Workout $session): void
    {
        $session->update(['completed_at' => null]);
        $this->scheduler->sessionChanged($session);
    }

    /**
     * What the client did last time for each exercise in this session, e.g. "3 × 5 @ 75 kg".
     *
     * @return array<int, string> keyed by exercise id
     */
    public function lastTime(Workout $session): array
    {
        $exerciseIds = $session->items->pluck('exercise_id')->unique()->all();

        return WorkoutExercise::query()
            ->whereIn('exercise_id', $exerciseIds)
            ->where('workout_id', '!=', $session->id)
            ->whereHas('workout', fn ($q) => $q->where('client_id', $session->client_id)->whereNotNull('completed_at'))
            ->with('workout:id,completed_at')
            ->get()
            ->filter(fn (WorkoutExercise $item) => $this->summary($item->logged_sets ?? []) !== null)
            ->sortByDesc(fn (WorkoutExercise $item) => $item->workout->completed_at)
            ->unique('exercise_id')
            ->mapWithKeys(fn (WorkoutExercise $item) => [$item->exercise_id => $this->summary($item->logged_sets ?? [])])
            ->all();
    }

    /**
     * Done sets, with runs of identical sets grouped: "3 × 5 @ 75 kg, 1 × 3 @ 80 kg".
     *
     * @param  array<int, array<string, mixed>>  $sets
     */
    public function summary(array $sets): ?string
    {
        $done = collect($sets)->filter(fn ($s) => ($s['done'] ?? false) && (filled($s['reps'] ?? null) || filled($s['load'] ?? null)))->values();

        if ($done->isEmpty()) {
            return null;
        }

        /** @var Collection<int, array{count: int, reps: string, load: string}> $runs */
        $runs = collect();
        foreach ($done as $set) {
            $reps = (string) ($set['reps'] ?? '');
            $load = (string) ($set['load'] ?? '');
            $last = $runs->pop();

            if ($last && $last['reps'] === $reps && $last['load'] === $load) {
                $last['count']++;
                $runs->push($last);
            } else {
                if ($last) {
                    $runs->push($last);
                }
                $runs->push(['count' => 1, 'reps' => $reps, 'load' => $load]);
            }
        }

        return $runs->map(function (array $run) {
            $text = $run['count'].' × '.($run['reps'] !== '' ? $run['reps'] : '—');

            return $run['load'] !== '' ? $text.' @ '.$run['load'] : $text;
        })->implode(', ');
    }

    /**
     * @param  array<int, array<string, mixed>>  $sets
     * @return list<array{reps: string|null, load: string|null, rpe: string|null, done: bool}>
     */
    protected function normalize(array $sets): array
    {
        return collect($sets)
            ->map(fn (array $set) => [
                'reps' => filled($set['reps'] ?? null) ? (string) $set['reps'] : null,
                'load' => filled($set['load'] ?? null) ? (string) $set['load'] : null,
                'rpe' => filled($set['rpe'] ?? null) ? (string) $set['rpe'] : null,
                'done' => (bool) ($set['done'] ?? false),
            ])
            ->values()
            ->all();
    }
}
