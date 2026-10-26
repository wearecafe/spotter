<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation for a coach to join a team.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $invited_by
 * @property string $email
 * @property string $role
 * @property string $token
 * @property CarbonInterface|null $accepted_at
 * @property CarbonInterface $expires_at
 */
#[Fillable(['team_id', 'invited_by', 'email', 'role', 'token', 'accepted_at', 'expires_at'])]
class TeamInvitation extends Model
{
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Not yet used and not expired.
     *
     * @param  Builder<TeamInvitation>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }
}
