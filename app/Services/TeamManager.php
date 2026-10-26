<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\InboxItem;
use App\Models\Program;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moving clients and coaches around inside a team.
 */
class TeamManager
{
    /**
     * Hand a client to another coach in the same team. Their upcoming workouts,
     * programs and open inbox items move with them; history stays as it was.
     */
    public function reassign(User $client, User $coach): void
    {
        if (! $coach->isCoach() || $coach->team_id === null || $coach->team_id !== $client->team_id) {
            throw new InvalidArgumentException('Clients can only move to a coach in the same team.');
        }

        DB::transaction(function () use ($client, $coach) {
            $client->forceFill(['coach_id' => $coach->id])->save();

            Workout::query()->where('client_id', $client->id)->whereNull('completed_at')->update(['coach_id' => $coach->id]);
            Program::query()->where('client_id', $client->id)->update(['coach_id' => $coach->id]);
            InboxItem::query()->where('client_id', $client->id)->where('status', '!=', 'done')->update(['coach_id' => $coach->id]);
        });
    }

    /**
     * Remove a coach from the team, handing their clients to another coach first.
     */
    public function removeCoach(User $coach, User $handTo): void
    {
        if ($coach->isTeamOwner()) {
            throw new InvalidArgumentException('The team owner can’t be removed.');
        }

        DB::transaction(function () use ($coach, $handTo) {
            User::query()->where('role', Role::Client)->where('coach_id', $coach->id)->get()
                ->each(fn (User $client) => $this->reassign($client, $handTo));

            // They leave with a fresh solo workspace of their own (and no clients).
            $team = Team::create(['name' => $coach->name, 'owner_id' => $coach->id]);
            $coach->forceFill(['team_id' => $team->id, 'team_role' => 'owner'])->save();
        });
    }

    /**
     * Accept an invitation: the coach joins the team. If they already coach clients
     * in a solo workspace, those clients come with them.
     */
    public function accept(TeamInvitation $invitation, User $coach): void
    {
        DB::transaction(function () use ($invitation, $coach) {
            $previous = $coach->team;

            $coach->forceFill(['team_id' => $invitation->team_id, 'team_role' => $invitation->role, 'onboarded_at' => $coach->onboarded_at ?? now()])->save();
            User::query()->where('role', Role::Client)->where('coach_id', $coach->id)->update(['team_id' => $invitation->team_id]);
            $invitation->update(['accepted_at' => now()]);

            // Tidy up a now-empty solo workspace.
            if ($previous && $previous->id !== $invitation->team_id && ! User::where('team_id', $previous->id)->exists()) {
                $previous->delete();
            }
        });
    }
}
