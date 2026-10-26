<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property Role $role
 * @property int|null $coach_id
 * @property string|null $goal
 * @property string|null $coach_notes
 * @property string|null $invitation_token
 * @property Carbon|null $invitation_accepted_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'coach_id', 'goal', 'coach_notes', 'invitation_token', 'invitation_accepted_at'])]
#[Hidden(['password', 'invitation_token', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'invitation_accepted_at' => 'datetime',
        ];
    }

    public function isCoach(): bool
    {
        return $this->role === Role::Coach;
    }

    public function isClient(): bool
    {
        return $this->role === Role::Client;
    }

    public function hasAcceptedInvitation(): bool
    {
        return $this->invitation_accepted_at !== null;
    }

    /**
     * The coach a client belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    /**
     * Clients managed by this coach.
     *
     * @return HasMany<User, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(User::class, 'coach_id')->where('role', Role::Client);
    }

    /**
     * Exercises this coach has added to their own library.
     *
     * @return HasMany<Exercise, $this>
     */
    public function exercises(): HasMany
    {
        return $this->hasMany(Exercise::class, 'coach_id');
    }

    /**
     * Workout templates in this coach's library.
     *
     * @return HasMany<Workout, $this>
     */
    public function templates(): HasMany
    {
        return $this->hasMany(Workout::class, 'coach_id')->whereNull('client_id');
    }

    /**
     * Workouts assigned to this client.
     *
     * @return HasMany<Workout, $this>
     */
    public function assignedWorkouts(): HasMany
    {
        return $this->hasMany(Workout::class, 'client_id');
    }

    /**
     * Share of this client's past-due workouts that were completed (0-100), or null if none were due.
     */
    public function complianceRate(int $days = 30): ?int
    {
        $due = $this->assignedWorkouts()
            ->whereBetween('scheduled_on', [now()->subDays($days)->toDateString(), now()->toDateString()]);

        $total = (clone $due)->count();

        if ($total === 0) {
            return null;
        }

        return (int) round((clone $due)->whereNotNull('completed_at')->count() / $total * 100);
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeClientsOf(Builder $query, User $coach): void
    {
        $query->where('role', Role::Client)->where('coach_id', $coach->id);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
