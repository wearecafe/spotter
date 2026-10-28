<?php

namespace App\Models;

use App\Enums\Role;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant: a solo coach's workspace, or a gym with a team of trainers.
 *
 * @property int $id
 * @property string $name
 * @property int|null $owner_id
 * @property string $plan
 * @property bool $is_demo
 * @property CarbonInterface|null $comp_until
 * @property CarbonInterface|null $suspended_at
 * @property string|null $suspended_reason
 * @property string|null $staff_notes
 * @property CarbonInterface $created_at
 */
#[Fillable(['name', 'owner_id', 'plan', 'comp_until', 'suspended_at', 'suspended_reason', 'staff_notes'])]
class Team extends Model
{
    public const PLANS = ['starter' => 'Starter', 'coach' => 'Coach', 'team' => 'Team'];

    public const ROLES = ['owner' => 'Owner', 'admin' => 'Admin', 'coach' => 'Coach'];

    protected function casts(): array
    {
        return [
            'comp_until' => 'date',
            'is_demo' => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Coaches in the team (owner, admins and coaches).
     *
     * @return HasMany<User, $this>
     */
    public function coaches(): HasMany
    {
        return $this->hasMany(User::class)->where('role', Role::Coach);
    }

    /** @return HasMany<User, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(User::class)->where('role', Role::Client);
    }

    /** @return HasMany<TeamInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /** @return HasMany<StaffAuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(StaffAuditLog::class)->latest('created_at');
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isComped(): bool
    {
        return $this->comp_until !== null && $this->comp_until->gte(today());
    }

    public function planLabel(): string
    {
        return self::PLANS[$this->plan] ?? ucfirst($this->plan);
    }
}
