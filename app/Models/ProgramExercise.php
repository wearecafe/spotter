<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An exercise in a program day's blueprint: the prescription and its progression rule.
 * Values apply from base_week on; later weeks progress from what the client actually logged.
 *
 * @property int $id
 * @property int $program_day_id
 * @property int $exercise_id
 * @property int $position
 * @property int $sets
 * @property string|null $reps
 * @property string|null $load
 * @property string|null $target_rpe
 * @property int|null $rest_seconds
 * @property string|null $notes
 * @property string $progression
 * @property string|null $progression_step
 * @property int $base_week
 */
#[Fillable(['program_day_id', 'exercise_id', 'position', 'sets', 'reps', 'load', 'target_rpe', 'rest_seconds', 'notes', 'progression', 'progression_step', 'base_week'])]
class ProgramExercise extends Model
{
    /** Fields copied onto each scheduled session. */
    public const PRESCRIPTION_FIELDS = ['exercise_id', 'position', 'sets', 'reps', 'load', 'target_rpe', 'rest_seconds', 'notes', 'progression', 'progression_step'];

    /** @return BelongsTo<ProgramDay, $this> */
    public function day(): BelongsTo
    {
        return $this->belongsTo(ProgramDay::class, 'program_day_id');
    }

    /** @return BelongsTo<Exercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
