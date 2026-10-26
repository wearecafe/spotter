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
        return $workout->coach_id === $user->id || $workout->client_id === $user->id;
    }

    /**
     * Only the coach who owns a workout can change its programming.
     */
    public function update(User $user, Workout $workout): bool
    {
        return $user->isCoach() && $workout->coach_id === $user->id;
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
