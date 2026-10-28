<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workout;

class WorkoutPolicy
{
    /**
     * Coaches see everything they wrote; clients see workouts assigned to them.
     */
    public function view(User $user, Workout $workout): bool
    {
        return $workout->client_id === $user->id || $this->update($user, $workout);
    }

    /**
     * Only the coach who owns a workout can change its programming.
     */
    public function update(User $user, Workout $workout): bool
    {
        if (! $user->isCoach()) {
            return false;
        }

        // A client's workouts belong to whoever manages that client now, not whoever wrote them,
        // so a coach who leaves or hands a client over loses access to their history too.
        if ($workout->client_id) {
            return $user->managesClient($workout->client);
        }

        // Templates: the author, or a team admin for teammates' templates.
        return $workout->coach_id === $user->id
            || ($user->isTeamAdmin() && $workout->coach?->team_id === $user->team_id);
    }

    public function delete(User $user, Workout $workout): bool
    {
        return $this->update($user, $workout);
    }

    /**
     * A trainer can run a session for a client they manage, logging it on the client's behalf.
     */
    public function run(User $user, Workout $workout): bool
    {
        return $user->isCoach() && $workout->client_id !== null && $user->managesClient($workout->client);
    }

    /**
     * Only the assigned client can log results against a workout.
     */
    public function log(User $user, Workout $workout): bool
    {
        return $user->isClient() && $workout->client_id === $user->id;
    }
}
