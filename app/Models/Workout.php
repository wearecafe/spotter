<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\WorkoutFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $coach_id
 * @property int|null $client_id
 * @property int|null $template_id
 * @property string $title
 * @property string|null $instructions
 * @property CarbonInterface|null $scheduled_on
 * @property CarbonInterface|null $completed_at
 * @property string|null $client_feedback
 * @property int|null $program_id
 * @property int|null $week
 * @property int|null $day
 */
#[Fillable(['coach_id', 'client_id', 'template_id', 'title', 'instructions', 'scheduled_on', 'completed_at', 'client_feedback', 'program_id', 'week', 'day'])]
class Workout extends Model
{
    /** @use HasFactory<WorkoutFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'scheduled_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * @return BelongsTo<Workout, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Workout::class, 'template_id');
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return HasMany<WorkoutExercise, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(WorkoutExercise::class)->orderBy('position')->orderBy('id');
    }

    public function isTemplate(): bool
    {
        return $this->client_id === null;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function isMissed(): bool
    {
        return ! $this->isCompleted()
            && $this->scheduled_on !== null
            && $this->scheduled_on->lt(today());
    }

    /**
     * @param  Builder<Workout>  $query
     */
    public function scopeTemplates(Builder $query): void
    {
        $query->whereNull('client_id');
    }

    /**
     * Copy this workout (usually a template) onto a client's calendar.
     */
    public function assignTo(User $client, DateTimeInterface|string $date): Workout
    {
        return DB::transaction(function () use ($client, $date) {
            $copy = $this->replicate(['client_id', 'scheduled_on', 'completed_at', 'client_feedback', 'template_id', 'program_id', 'week', 'day'])->unsetRelations();
            $copy->client_id = $client->id;
            $copy->scheduled_on = $date;
            $copy->template_id = $this->isTemplate() ? $this->id : $this->template_id;
            $copy->save();

            foreach ($this->items as $item) {
                $copy->items()->create($item->only(WorkoutExercise::PRESCRIPTION_FIELDS));
            }

            return $copy;
        });
    }
}
