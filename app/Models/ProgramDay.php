<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One training day in a program's blueprint, repeated every week on a fixed weekday.
 *
 * @property int $id
 * @property int $program_id
 * @property int $position
 * @property string $title
 * @property int $weekday ISO weekday, 1 = Monday … 7 = Sunday
 * @property string|null $notes
 * @property array<int, int>|null $skipped_weeks
 */
#[Fillable(['program_id', 'position', 'title', 'weekday', 'notes', 'skipped_weeks'])]
class ProgramDay extends Model
{
    protected function casts(): array
    {
        return ['skipped_weeks' => 'array'];
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return HasMany<ProgramExercise, $this> */
    public function exercises(): HasMany
    {
        return $this->hasMany(ProgramExercise::class)->orderBy('position')->orderBy('id');
    }

    /** Calendar sessions scheduled from this day. @return HasMany<Workout, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(Workout::class)->orderBy('week');
    }

    public function weekdayName(): string
    {
        return now()->startOfWeek()->addDays($this->weekday - 1)->translatedFormat('l');
    }
}
