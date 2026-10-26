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
 * @property array<int, string>|null $photos storage paths on the media disk
 * @property CarbonInterface|null $read_at
 * @property CarbonInterface|null $notified_at
 * @property CarbonInterface $created_at
 */
#[Fillable(['coach_id', 'client_id', 'sender_id', 'body', 'photos', 'read_at', 'notified_at'])]
class Message extends Model
{
    protected function casts(): array
    {
        return ['photos' => 'array', 'read_at' => 'datetime', 'notified_at' => 'datetime'];
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
     * A client's conversation with their coaching team. It follows the client,
     * so history isn't lost if they move to another coach in the gym.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeThread(Builder $query, User $client): void
    {
        $query->where('client_id', $client->id);
    }

    /**
     * Who should hear about this message: the client, or the client's current coach.
     */
    public function recipient(): ?User
    {
        return $this->fromClient() ? $this->client?->coach : $this->client;
    }

    /**
     * Short preview for lists and emails.
     */
    public function preview(int $limit = 80): string
    {
        if (trim($this->body) !== '') {
            return str($this->body)->squish()->limit($limit)->toString();
        }

        return trans_choice('Sent a photo|Sent :count photos', count($this->photos ?? []));
    }
}
