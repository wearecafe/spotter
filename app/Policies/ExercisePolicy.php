<?php

namespace App\Policies;

use App\Models\Exercise;
use App\Models\User;

class ExercisePolicy
{
    /**
     * Shared library exercises are read-only; coaches can edit their own.
     */
    public function update(User $user, Exercise $exercise): bool
    {
        return $exercise->coach_id === $user->id;
    }

    public function delete(User $user, Exercise $exercise): bool
    {
        return $this->update($user, $exercise);
    }
}
