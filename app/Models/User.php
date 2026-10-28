<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
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
 * @property bool $in_person
 * @property string|null $coach_notes
 * @property string|null $invitation_token
 * @property Carbon|null $invitation_accepted_at
 * @property Carbon|null $invited_at
 * @property string $locale
 * @property string|null $business_name
 * @property string $currency
 * @property string $coaching_languages
 * @property string|null $client_count_band
 * @property string $brand_color
 * @property string|null $logo_path
 * @property string|null $subdomain
 * @property string|null $import_source
 * @property int $contract_months_left
 * @property Carbon|null $onboarded_at
 * @property int|null $team_id
 * @property string|null $team_role
 * @property bool $is_staff
 * @property Carbon|null $last_seen_at
 * @property int|null $calorie_target
 * @property int|null $protein_target
 * @property int|null $carbs_target
 * @property int|null $fat_target
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'email', 'password', 'role', 'coach_id', 'goal', 'in_person', 'coach_notes', 'invitation_token', 'invitation_accepted_at', 'invited_at',
    'locale', 'business_name', 'currency', 'coaching_languages', 'client_count_band', 'brand_color', 'logo_path', 'subdomain',
    'import_source', 'contract_months_left', 'onboarded_at',
    'calorie_target', 'protein_target', 'carbs_target', 'fat_target',
    // team_id, team_role and is_staff are deliberately not mass assignable.
])]
#[Hidden(['password', 'invitation_token', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Match the column defaults so new models behave the same before and after saving.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'locale' => 'en',
        'currency' => 'CAD',
        'coaching_languages' => 'en',
        'brand_color' => '#0A7A55',
        'contract_months_left' => 0,
        'in_person' => false,
    ];

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
            'invited_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'is_staff' => 'boolean',
            'in_person' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Clients belong to their coach's team.
        static::creating(function (User $user) {
            if ($user->role === Role::Client && $user->coach_id && ! $user->team_id) {
                $user->team_id = User::whereKey($user->coach_id)->value('team_id');
            }
        });

        // Every coach works inside a team: their own workspace until they join or build a bigger one.
        static::created(function (User $user) {
            if ($user->role === Role::Coach && ! $user->team_id) {
                $team = Team::create(['name' => $user->business_name ?: $user->name, 'owner_id' => $user->id]);
                $user->forceFill(['team_id' => $team->id, 'team_role' => 'owner'])->saveQuietly();
            }
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isTeamOwner(): bool
    {
        return $this->isCoach() && $this->team_role === 'owner';
    }

    /**
     * Owners and admins can see and manage every client in the team.
     */
    public function isTeamAdmin(): bool
    {
        return $this->isCoach() && in_array($this->team_role, ['owner', 'admin'], true);
    }

    /**
     * Whether this coach may manage a client: their own, or any client in the team if they're an admin.
     */
    public function managesClient(?User $client): bool
    {
        if (! $client || ! $this->isCoach() || ! $client->isClient()) {
            return false;
        }

        return $client->coach_id === $this->id
            || ($this->isTeamAdmin() && $this->team_id !== null && $client->team_id === $this->team_id);
    }

    /**
     * Clients this coach can see: their own, or the whole team's for admins.
     *
     * @return Builder<User>
     */
    public function visibleClients(): Builder
    {
        if ($this->isTeamAdmin() && $this->team_id) {
            return User::query()->where('role', Role::Client)->where('team_id', $this->team_id);
        }

        return User::query()->where('role', Role::Client)->where('coach_id', $this->id);
    }

    /**
     * Ids of every coach in this coach's team (shared exercise library and templates).
     *
     * @return array<int, int>
     */
    public function teamCoachIds(): array
    {
        if (! $this->team_id) {
            return [$this->id];
        }

        return User::query()->where('team_id', $this->team_id)->where('role', Role::Coach)->pluck('id')->all();
    }

    /**
     * Emails and notifications go out in the user's language.
     */
    public function preferredLocale(): string
    {
        return $this->locale ?: config('app.locale');
    }

    public function isCoach(): bool
    {
        return $this->role === Role::Coach;
    }

    public function isClient(): bool
    {
        return $this->role === Role::Client;
    }

    public function hasOnboarded(): bool
    {
        return $this->onboarded_at !== null;
    }

    /**
     * What clients see as this coach's brand.
     */
    public function brandName(): string
    {
        return $this->business_name ?: $this->name;
    }

    /**
     * Whose brand a user sees: the team owner's (a gym's brand), falling back to the coach.
     */
    public function brandOwner(): ?User
    {
        $coach = $this->isCoach() ? $this : $this->coach;

        return $coach?->team?->owner ?? $coach;
    }

    /**
     * @return HasMany<NutritionLog, $this>
     */
    public function nutritionLogs(): HasMany
    {
        return $this->hasMany(NutritionLog::class, 'client_id');
    }

    public function hasNutritionTargets(): bool
    {
        return $this->calorie_target !== null || $this->protein_target !== null;
    }

    /**
     * Trains in person and doesn't use the app: no invite to send, and the trainer logs every session.
     */
    public function isInPersonOnly(): bool
    {
        return $this->isClient() && $this->in_person && ! $this->hasAcceptedInvitation();
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
     * @return HasMany<Program, $this>
     */
    public function programs(): HasMany
    {
        return $this->hasMany(Program::class, 'client_id');
    }

    /**
     * @return HasMany<CheckIn, $this>
     */
    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class, 'client_id');
    }

    /**
     * @return HasMany<InboxItem, $this>
     */
    public function inboxItems(): HasMany
    {
        return $this->hasMany(InboxItem::class, 'coach_id');
    }

    /**
     * @return HasMany<Import, $this>
     */
    public function imports(): HasMany
    {
        return $this->hasMany(Import::class, 'coach_id');
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
