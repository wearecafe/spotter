<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client's weekly check-in.
 *
 * @property int $id
 * @property int $client_id
 * @property CarbonInterface $week_of
 * @property string|null $weight
 * @property string $weight_unit
 * @property string|null $sleep_hours
 * @property int|null $avg_steps
 * @property int|null $energy
 * @property int|null $stress
 * @property string|null $notes
 * @property array<int, string>|null $photos
 * @property CarbonInterface $created_at
 */
#[Fillable(['client_id', 'week_of', 'weight', 'weight_unit', 'sleep_hours', 'avg_steps', 'energy', 'stress', 'notes', 'photos'])]
class CheckIn extends Model
{
    protected function casts(): array
    {
        return [
            'week_of' => DateOnly::class,
            'photos' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * The check-in the client submitted the week before this one.
     */
    public function previous(): ?CheckIn
    {
        return static::query()
            ->where('client_id', $this->client_id)
            ->where('week_of', '<', $this->week_of)
            ->latest('week_of')
            ->first();
    }
}
