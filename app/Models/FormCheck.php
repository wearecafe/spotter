<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A video a client recorded of one exercise so the coach can review their form.
 *
 * @property int $id
 * @property int $client_id
 * @property int $workout_exercise_id
 * @property string $video_path
 * @property string|null $note
 */
#[Fillable(['client_id', 'workout_exercise_id', 'video_path', 'note'])]
class FormCheck extends Model
{
    /** @return BelongsTo<User, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /** @return BelongsTo<WorkoutExercise, $this> */
    public function workoutExercise(): BelongsTo
    {
        return $this->belongsTo(WorkoutExercise::class);
    }
}
