<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workout>
 */
class WorkoutFactory extends Factory
{
    public function definition(): array
    {
        return [
            'coach_id' => User::factory(),
            'client_id' => null,
            'title' => fake()->randomElement(['Upper A', 'Lower A', 'Push', 'Pull', 'Legs', 'Full Body', 'Conditioning']),
            'instructions' => fake()->optional()->sentence(),
        ];
    }

    public function forClient(User $client, $date = null): static
    {
        return $this->state(fn () => [
            'coach_id' => $client->coach_id,
            'client_id' => $client->id,
            'scheduled_on' => $date ?? today(),
        ]);
    }
}
