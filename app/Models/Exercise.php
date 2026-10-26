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
 * @property string|null $source
 * @property string|null $source_id
 * @property string|null $category
 * @property string|null $level
 * @property array<int, string>|null $secondary_muscles
 * @property array<int, string>|null $images
 */
#[Fillable(['coach_id', 'name', 'muscle_group', 'equipment', 'instructions', 'video_url', 'source', 'source_id', 'category', 'level', 'secondary_muscles', 'images'])]
class Exercise extends Model
{
    /** @use HasFactory<ExerciseFactory> */
    use HasFactory;

    public const MUSCLE_GROUPS = [
        'Chest', 'Back', 'Shoulders', 'Biceps', 'Triceps', 'Forearms', 'Quads', 'Hamstrings',
        'Glutes', 'Hips', 'Calves', 'Core', 'Neck', 'Full body', 'Cardio', 'Mobility',
    ];

    protected function casts(): array
    {
        return [
            'secondary_muscles' => 'array',
            'images' => 'array',
        ];
    }

    /**
     * Full URLs for the exercise's photos (start and end positions for library exercises).
     *
     * @return array<int, string>
     */
    public function imageUrls(): array
    {
        $base = rtrim((string) config('services.exercise_images.base_url'), '/');

        return collect($this->images ?? [])
            ->map(fn (string $path) => str_starts_with($path, 'http') ? $path : $base.'/'.ltrim($path, '/'))
            ->values()
            ->all();
    }

    public function hasDemo(): bool
    {
        return $this->video_url !== null || ! empty($this->images) || filled($this->instructions);
    }

    /**
     * @return array<int, string>
     */
    public function instructionSteps(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R+/', (string) $this->instructions) ?: [])));
    }

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
