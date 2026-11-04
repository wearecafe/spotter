<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Support\Load;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * How each lift has moved for a client, across every program and one-off workout.
 *
 * Every completed session of an exercise becomes one point, measured three ways: the heaviest
 * set, an estimated 1-rep max, and total volume. The coach picks which one to show.
 * A done set with a blank load or reps counts as prescribed (the logger shows the
 * prescription as the placeholder), matching the progression rules.
 */
class ExerciseHistory
{
    public const METRICS = [
        'heaviest' => 'Heaviest set',
        'e1rm' => 'Estimated 1-rep max',
        'volume' => 'Total volume',
    ];

    /** Above this many reps an estimated max is too rough to be useful. */
    protected const E1RM_MAX_REPS = 12;

    /**
     * One row per exercise the client has logged, with first, best and latest values.
     *
     * @return Collection<int, array{exercise: Exercise, unit: string, sessions: int, first: array<string, mixed>, best: array<string, mixed>, latest: array<string, mixed>, change: float|null}>
     */
    public function summary(User $client, string $metric = 'heaviest'): Collection
    {
        $metric = $this->metric($metric);

        return $this->loggedItems($client)
            ->groupBy('exercise_id')
            ->map(fn (Collection $items) => $this->describe($items, $metric))
            ->filter()
            ->sortByDesc(fn (array $row) => $row['latest']['date'])
            ->values();
    }

    /**
     * Every completed session of one exercise, oldest first.
     *
     * @return array{exercise: Exercise, unit: string, sessions: int, first: array<string, mixed>, best: array<string, mixed>, latest: array<string, mixed>, change: float|null, points: Collection<int, array<string, mixed>>}|null
     */
    public function forExercise(User $client, int $exerciseId, string $metric = 'heaviest'): ?array
    {
        return $this->describe($this->loggedItems($client, $exerciseId), $this->metric($metric));
    }

    public function metric(?string $metric): string
    {
        return array_key_exists((string) $metric, self::METRICS) ? (string) $metric : 'heaviest';
    }

    /**
     * A metric's value in the client's unit, ready to show: "102.5 kg", "4,350 lb".
     */
    public function format(?float $kg, string $unit): string
    {
        if ($kg === null) {
            return '—';
        }

        $value = $unit === 'lb' ? $kg / 0.45359237 : $kg;
        $decimals = $value >= 1000 || fmod(round($value, 1), 1.0) === 0.0 ? 0 : 1;

        return number_format($value, $decimals).' '.$unit;
    }

    /**
     * @return Collection<int, WorkoutExercise>
     */
    protected function loggedItems(User $client, ?int $exerciseId = null): Collection
    {
        return WorkoutExercise::query()
            ->when($exerciseId, fn ($q) => $q->where('exercise_id', $exerciseId))
            ->whereNotNull('logged_sets')
            ->whereHas('workout', fn ($q) => $q->where('client_id', $client->id)->whereNotNull('completed_at'))
            ->with(['workout:id,scheduled_on,completed_at,title', 'exercise'])
            ->get();
    }

    /**
     * @param  Collection<int, WorkoutExercise>  $items
     * @return array<string, mixed>|null
     */
    protected function describe(Collection $items, string $metric): ?array
    {
        $points = $items
            ->map(fn (WorkoutExercise $item) => $this->point($item))
            ->filter(fn (?array $point) => $point !== null && $point[$metric] !== null)
            ->sortBy(fn (array $point) => $point['date']->getTimestamp())
            ->values();

        if ($points->isEmpty()) {
            return null;
        }

        $first = $points->first();
        $latest = $points->last();
        $best = $points->sortByDesc($metric)->first();

        return [
            'exercise' => $items->first()->exercise,
            // Shown in the unit the client logs in now.
            'unit' => $latest['unit'],
            'sessions' => $points->count(),
            'first' => $first,
            'best' => $best,
            'latest' => $latest,
            'change' => $first[$metric] > 0 && $points->count() > 1 ? round(($latest[$metric] - $first[$metric]) / $first[$metric] * 100) : null,
            'points' => $points,
        ];
    }

    /**
     * One session of an exercise, measured every way.
     *
     * @return array{date: CarbonInterface, workout_id: int, title: string, unit: string, sets: string, heaviest: float|null, heaviest_reps: float|null, e1rm: float|null, volume: float|null}|null
     */
    protected function point(WorkoutExercise $item): ?array
    {
        $sets = collect($item->logged_sets ?? [])->filter(fn ($s) => $s['done'] ?? false);

        if ($sets->isEmpty()) {
            return null;
        }

        $measured = $sets->map(function (array $set) use ($item) {
            $load = filled($set['load'] ?? null) ? (string) $set['load'] : $item->load;
            $reps = filled($set['reps'] ?? null) ? (float) str_replace(',', '.', (string) $set['reps']) : Load::low($item->reps);

            return ['kg' => Load::toKg($load), 'unit' => Load::parse($load)['unit'], 'reps' => $reps];
        })->filter(fn (array $s) => $s['kg'] !== null && $s['kg'] > 0);

        $heaviest = $measured->sortByDesc('kg')->first();
        $withReps = $measured->filter(fn (array $s) => $s['reps'] !== null && $s['reps'] > 0);

        $e1rm = $withReps
            ->filter(fn (array $s) => $s['reps'] <= self::E1RM_MAX_REPS)
            // Epley: weight × (1 + reps / 30); a single is its own max.
            ->map(fn (array $s) => $s['reps'] <= 1 ? $s['kg'] : $s['kg'] * (1 + $s['reps'] / 30))
            ->max();

        $volume = $withReps->sum(fn (array $s) => $s['kg'] * $s['reps']);

        return [
            'date' => $item->workout->completed_at,
            'workout_id' => $item->workout_id,
            'title' => $item->workout->title,
            'unit' => $measured->pluck('unit')->filter()->last() ?? 'kg',
            'sets' => (string) app(TrainerSession::class)->summary($item->logged_sets ?? []),
            'heaviest' => $heaviest ? round($heaviest['kg'], 2) : null,
            'heaviest_reps' => $heaviest['reps'] ?? null,
            'e1rm' => $e1rm ? round($e1rm, 2) : null,
            'volume' => $volume > 0 ? round($volume, 1) : null,
        ];
    }
}
