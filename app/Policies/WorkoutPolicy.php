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

        if ($workout->coach_id === $user->id) {
            return true;
        }

        // Team admins can program any team client, and edit teammates' templates.
        return $workout->client_id
            ? $user->managesClient($workout->client)
            : $user->isTeamAdmin() && $workout->coach?->team_id === $user->team_id;
    }

    public function delete(User $user, Workout $workout): bool
    {
        return $this->update($user, $workout);
    }

    /**
     * Only the assigned client can log results against a workout.
     */
    public function log(User $user, Workout $workout): bool
    {
        return $user->isClient() && $workout->client_id === $user->id;
    }
}
