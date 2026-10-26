<?php

namespace App\Policies;

use App\Models\Exercise;
use App\Models\User;

class ExercisePolicy
{
    /**
     * Shared library exercises are read-only; coaches can edit their own, and team admins their team's.
     */
    public function update(User $user, Exercise $exercise): bool
    {
        if ($exercise->coach_id === null || ! $user->isCoach()) {
            return false;
        }

        return $exercise->coach_id === $user->id
            || ($user->isTeamAdmin() && in_array($exercise->coach_id, $user->teamCoachIds(), true));
    }

    public function delete(User $user, Exercise $exercise): bool
    {
        return $this->update($user, $exercise);
    }
}
