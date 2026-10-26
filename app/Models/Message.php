<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $coach_id
 * @property int $client_id
 * @property int $sender_id
 * @property string $body
 * @property CarbonInterface|null $read_at
 * @property CarbonInterface $created_at
 */
#[Fillable(['coach_id', 'client_id', 'sender_id', 'body', 'read_at'])]
class Message extends Model
{
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return BelongsTo<User, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function fromClient(): bool
    {
        return $this->sender_id === $this->client_id;
    }

    /**
     * The conversation between a client and their coach.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeThread(Builder $query, User $client): void
    {
        $query->where('client_id', $client->id)->where('coach_id', $client->coach_id);
    }
}
