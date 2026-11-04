<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of a habit.
 *
 * @property int $id
 * @property int $habit_id
 * @property CarbonInterface $logged_on
 * @property string|null $value
 * @property bool $done
 */
#[Fillable(['habit_id', 'logged_on', 'value', 'done'])]
class HabitLog extends Model
{
    protected function casts(): array
    {
        return [
            'logged_on' => DateOnly::class,
            'value' => 'decimal:2',
            'done' => 'boolean',
        ];
    }

    /** @return BelongsTo<Habit, $this> */
    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }
}
