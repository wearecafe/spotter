<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Something a client does outside their workouts: a daily step target, a 30-minute walk 3 times
 * a week, 2 litres of water. A "check" habit is ticked off; a "number" habit is logged as a
 * value and counts as done once it reaches the target.
 *
 * @property int $id
 * @property int $client_id
 * @property int|null $created_by_id
 * @property string $name
 * @property string $type
 * @property string|null $target
 * @property string|null $unit
 * @property int $days_per_week
 * @property int $position
 * @property CarbonInterface|null $ended_at
 * @property CarbonInterface $created_at
 */
#[Fillable(['client_id', 'created_by_id', 'name', 'type', 'target', 'unit', 'days_per_week', 'position', 'ended_at'])]
class Habit extends Model
{
    public const TYPES = ['check' => 'Tick it off', 'number' => 'Log a number'];

    protected $attributes = ['type' => 'check', 'days_per_week' => 7, 'position' => 0];

    protected function casts(): array
    {
        return [
            'target' => 'decimal:2',
            'days_per_week' => 'integer',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return HasMany<HabitLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(HabitLog::class);
    }

    /**
     * @param  Builder<Habit>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    public function isNumber(): bool
    {
        return $this->type === 'number';
    }

    public function isDaily(): bool
    {
        return $this->days_per_week >= 7;
    }

    /**
     * Added by the client themself (the coach can see it but it's theirs).
     */
    public function isClientOwn(): bool
    {
        return $this->created_by_id === $this->client_id;
    }

    /**
     * "8,000 steps", "2 litres", or the bare number when there's no unit.
     */
    public function formatValue(float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $value = (float) $value;
        $number = number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 1);

        return trim($number.' '.$this->unit);
    }

    /**
     * "Daily · 8,000 steps", "3× a week".
     */
    public function describe(): string
    {
        $frequency = $this->isDaily() ? __('Daily') : __(':count× a week', ['count' => $this->days_per_week]);

        return $this->isNumber() && $this->target !== null
            ? $frequency.' · '.__('target :target', ['target' => $this->formatValue($this->target)])
            : $frequency;
    }

    /**
     * Record a day. Number habits are done once the value reaches the target (or any value, without one).
     */
    public function log(CarbonInterface|string $day, ?float $value = null, ?bool $done = null): HabitLog
    {
        $day = Carbon::parse($day)->toDateString();

        if ($this->isNumber()) {
            $done = $value !== null && ($this->target === null ? $value > 0 : $value >= (float) $this->target);
        }

        return $this->logs()->updateOrCreate(['logged_on' => $day], ['value' => $this->isNumber() ? $value : null, 'done' => (bool) $done]);
    }

    /**
     * Days done in the ISO week containing the given day, against the habit's goal for the week.
     *
     * @return array{done: int, goal: int, met: bool}
     */
    public function weekProgress(CarbonInterface|string|null $day = null): array
    {
        $start = Carbon::parse($day ?? today())->startOfWeek();
        $done = $this->relationLoaded('logs')
            ? $this->logs->filter(fn (HabitLog $l) => $l->done && $l->logged_on->betweenIncluded($start, $start->copy()->endOfWeek()))->count()
            : $this->logs()->where('done', true)->whereBetween('logged_on', [$start->toDateString(), $start->copy()->endOfWeek()->toDateString()])->count();

        return ['done' => $done, 'goal' => $this->days_per_week, 'met' => $done >= $this->days_per_week];
    }
}
