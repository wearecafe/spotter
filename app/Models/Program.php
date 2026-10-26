<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A multi-week block of training for one client. Each week is a set of workouts
 * (days); the next week is generated from the last one using each row's progression rule.
 *
 * @property int $id
 * @property int $coach_id
 * @property int $client_id
 * @property string $title
 * @property CarbonInterface $starts_on
 * @property int $planned_weeks
 */
#[Fillable(['coach_id', 'client_id', 'title', 'starts_on', 'planned_weeks'])]
class Program extends Model
{
    protected function casts(): array
    {
        return ['starts_on' => 'date'];
    }

    /** @return BelongsTo<User, $this> */
    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    /** @return BelongsTo<User, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /** @return HasMany<Workout, $this> */
    public function workouts(): HasMany
    {
        return $this->hasMany(Workout::class)->orderBy('week')->orderBy('day');
    }

    public function lastWeek(): int
    {
        return (int) $this->workouts()->max('week');
    }
}
