<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\CheckIn;
use App\Models\Import;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\NutritionLog;
use App\Models\Team;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Usage numbers for the backoffice. Counts only: staff never see client names or health data.
 */
class TenantStats
{
    /** Launch list prices (USD), used for an estimate while billing isn't live. */
    public const PRICES = ['starter' => 0, 'coach' => 39, 'team' => 99];

    public const COACH_INCLUDED_CLIENTS = 15;

    public const EXTRA_CLIENT_PRICE = 1.5;

    /**
     * Teams with member counts and last activity, for the tenant list.
     *
     * @return Builder<Team>
     */
    public function teamsQuery(): Builder
    {
        return Team::query()
            ->with('owner:id,name,email')
            ->withCount([
                'coaches',
                'clients',
                'clients as active_clients_count' => fn ($q) => $q->where('last_seen_at', '>=', now()->subDays(7)),
            ])
            ->addSelect(['last_active_at' => User::query()->selectRaw('max(last_seen_at)')->whereColumn('team_id', 'teams.id')]);
    }

    /**
     * What this team would pay a month at launch prices.
     */
    public function monthlyEstimate(Team $team, int $clients): float
    {
        if ($team->isComped() || $team->isSuspended()) {
            return 0;
        }

        return match ($team->plan) {
            'coach' => self::PRICES['coach'] + max(0, $clients - self::COACH_INCLUDED_CLIENTS) * self::EXTRA_CLIENT_PRICE,
            'team' => self::PRICES['team'],
            default => 0,
        };
    }

    /**
     * @return array<string, int|float>
     */
    public function platform(): array
    {
        // The demo gym (/demo) isn't a customer, so it stays out of the totals.
        $teams = $this->teamsQuery()->where('is_demo', false)->get();
        $demoTeams = Team::query()->where('is_demo', true)->select('id');
        $realUsers = fn () => User::query()->where(fn ($q) => $q->whereNull('team_id')->orWhereNotIn('team_id', $demoTeams));

        return [
            'teams' => $teams->count(),
            'new_teams_30d' => $teams->where('created_at', '>=', now()->subDays(30))->count(),
            'suspended' => $teams->filter->isSuspended()->count(),
            'coaches' => $realUsers()->where('role', Role::Coach)->count(),
            'clients' => $realUsers()->where('role', Role::Client)->count(),
            'active_clients_7d' => $realUsers()->where('role', Role::Client)->where('last_seen_at', '>=', now()->subDays(7))->count(),
            'workouts_7d' => Workout::whereIn('client_id', $realUsers()->select('id'))->where('completed_at', '>=', now()->subDays(7))->count(),
            'mrr_estimate' => $teams->sum(fn (Team $t) => $this->monthlyEstimate($t, $t->clients_count)),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function forTeam(Team $team, int $days = 30): array
    {
        $since = now()->subDays($days);
        $clientIds = DB::table('users')->where('team_id', $team->id)->where('role', Role::Client->value)->pluck('id');
        $coachIds = DB::table('users')->where('team_id', $team->id)->where('role', Role::Coach->value)->pluck('id');

        return [
            'clients' => $clientIds->count(),
            'clients_pending_invite' => User::whereIn('id', $clientIds)->whereNull('invitation_accepted_at')->count(),
            'active_clients_7d' => User::whereIn('id', $clientIds)->where('last_seen_at', '>=', now()->subDays(7))->count(),
            'workouts_completed' => Workout::whereIn('client_id', $clientIds)->where('completed_at', '>=', $since)->count(),
            'check_ins' => CheckIn::whereIn('client_id', $clientIds)->where('created_at', '>=', $since)->count(),
            'nutrition_days' => NutritionLog::whereIn('client_id', $clientIds)->where('created_at', '>=', $since)->count(),
            'messages' => Message::whereIn('client_id', $clientIds)->where('created_at', '>=', $since)->count(),
            'inbox_ai' => InboxItem::whereIn('coach_id', $coachIds)->where('created_at', '>=', $since)->where('drafted_by', 'ai')->count(),
            'inbox_rules' => InboxItem::whereIn('coach_id', $coachIds)->where('created_at', '>=', $since)->where('drafted_by', 'rules')->count(),
            'imports' => Import::whereIn('coach_id', $coachIds)->count(),
        ];
    }
}
