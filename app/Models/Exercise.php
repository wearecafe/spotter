<?php

namespace App\Models;

use Database\Factories\ExerciseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $coach_id
 * @property string $name
 * @property string|null $muscle_group
 * @property string|null $equipment
 * @property string|null $instructions
 * @property string|null $video_url
 */
#[Fillable(['coach_id', 'name', 'muscle_group', 'equipment', 'instructions', 'video_url'])]
class Exercise extends Model
{
    /** @use HasFactory<ExerciseFactory> */
    use HasFactory;

    public const MUSCLE_GROUPS = [
        'Chest', 'Back', 'Shoulders', 'Biceps', 'Triceps', 'Quads', 'Hamstrings',
        'Glutes', 'Calves', 'Core', 'Full body', 'Cardio', 'Mobility',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function isShared(): bool
    {
        return $this->coach_id === null;
    }

    /**
     * Exercises a coach can use: the shared library plus any their team has added.
     *
     * @param  Builder<Exercise>  $query
     */
    public function scopeAvailableTo(Builder $query, User $coach): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('coach_id')->orWhereIn('coach_id', $coach->teamCoachIds()));
    }
}
