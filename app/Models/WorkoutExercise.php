<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An exercise prescribed within a workout, with the client's logged results.
 *
 * @property int $id
 * @property int $workout_id
 * @property int $exercise_id
 * @property int $position
 * @property int $sets
 * @property string|null $reps
 * @property string|null $load
 * @property int|null $rest_seconds
 * @property string|null $notes
 * @property array<int, array{reps: string|null, load: string|null, done: bool}>|null $logged_sets
 */
#[Fillable(['exercise_id', 'position', 'sets', 'reps', 'load', 'rest_seconds', 'notes', 'logged_sets'])]
class WorkoutExercise extends Model
{
    protected function casts(): array
    {
        return [
            'logged_sets' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Workout, $this>
     */
    public function workout(): BelongsTo
    {
        return $this->belongsTo(Workout::class);
    }

    /**
     * @return BelongsTo<Exercise, $this>
     */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * Human readable prescription, e.g. "3 × 8-10 @ 135 lb · 90s rest".
     */
    public function prescription(): string
    {
        $text = $this->sets.' × '.($this->reps ?: '—');

        if ($this->load) {
            $text .= ' @ '.$this->load;
        }

        if ($this->rest_seconds) {
            $text .= ' · '.$this->rest_seconds.'s rest';
        }

        return $text;
    }

    /**
     * Logged sets padded to the prescribed set count, ready for a form.
     *
     * @return array<int, array{reps: string|null, load: string|null, done: bool}>
     */
    public function setsForLogging(): array
    {
        $logged = $this->logged_sets ?? [];

        return collect(range(0, max($this->sets, 1) - 1))
            ->map(fn (int $i) => [
                'reps' => $logged[$i]['reps'] ?? null,
                'load' => $logged[$i]['load'] ?? null,
                'done' => (bool) ($logged[$i]['done'] ?? false),
            ])
            ->all();
    }
}
