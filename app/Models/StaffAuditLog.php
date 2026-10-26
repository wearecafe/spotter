<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * A record of something Spotter staff did in the backoffice.
 *
 * @property int $id
 * @property int|null $staff_id
 * @property int|null $team_id
 * @property string $action
 * @property array<string, mixed>|null $details
 * @property CarbonInterface $created_at
 */
#[Fillable(['staff_id', 'team_id', 'action', 'details'])]
class StaffAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function record(string $action, ?Team $team = null, array $details = []): self
    {
        return static::create([
            'staff_id' => Auth::id(),
            'team_id' => $team?->id,
            'action' => $action,
            'details' => $details ?: null,
        ]);
    }

    /** @return BelongsTo<User, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
