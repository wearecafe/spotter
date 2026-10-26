<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An exercise prescribed within a workout, with the client's logged results.
 *
 * @property int $id
 * @property int $workout_id
 * @property int $exercise_id
 * @property int|null $program_exercise_id
 * @property int $position
 * @property int $sets
 * @property string|null $reps
 * @property string|null $load
 * @property int|null $rest_seconds
 * @property string|null $notes
 * @property string|null $target_rpe
 * @property string $progression
 * @property string|null $progression_step
 * @property array<int, string>|null $changed_fields
 * @property string|null $change_reason
 * @property array<int, array{reps: string|null, load: string|null, rpe?: string|null, done: bool}>|null $logged_sets
 */
#[Fillable(['exercise_id', 'program_exercise_id', 'position', 'sets', 'reps', 'load', 'target_rpe', 'rest_seconds', 'notes', 'progression', 'progression_step', 'changed_fields', 'change_reason', 'logged_sets'])]
class WorkoutExercise extends Model
{
    /** Fields that describe what the coach prescribed (copied when a workout is assigned or duplicated). */
    public const PRESCRIPTION_FIELDS = ['exercise_id', 'position', 'sets', 'reps', 'load', 'target_rpe', 'rest_seconds', 'notes', 'progression', 'progression_step'];

    public const PROGRESSIONS = [
        'none' => 'No rule',
        'load' => 'Add load',
        'reps' => 'Add reps',
        'hold' => 'Hold',
    ];

    protected function casts(): array
    {
        return [
            'logged_sets' => 'array',
            'changed_fields' => 'array',
        ];
    }

    /**
     * @return HasMany<FormCheck, $this>
     */
    public function formChecks(): HasMany
    {
        return $this->hasMany(FormCheck::class);
    }

    public function progressionChanged(string $field): bool
    {
        return in_array($field, $this->changed_fields ?? [], true);
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

        if ($this->target_rpe) {
            $text .= ' · RPE '.$this->target_rpe;
        }

        if ($this->rest_seconds) {
            $text .= ' · '.$this->rest_seconds.'s rest';
        }

        return $text;
    }

    /**
     * Logged sets padded to the prescribed set count, ready for a form.
     *
     * @return array<int, array{reps: string|null, load: string|null, rpe: string|null, done: bool}>
     */
    public function setsForLogging(): array
    {
        $logged = $this->logged_sets ?? [];

        return collect(range(0, max($this->sets, 1) - 1))
            ->map(fn (int $i) => [
                'reps' => $logged[$i]['reps'] ?? null,
                'load' => $logged[$i]['load'] ?? null,
                'rpe' => $logged[$i]['rpe'] ?? null,
                'done' => (bool) ($logged[$i]['done'] ?? false),
            ])
            ->all();
    }
}
