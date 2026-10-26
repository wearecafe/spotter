<?php

namespace Database\Factories;

use App\Models\Exercise;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exercise>
 */
class ExerciseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'coach_id' => null,
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'muscle_group' => fake()->randomElement(Exercise::MUSCLE_GROUPS),
            'equipment' => fake()->randomElement(['Barbell', 'Dumbbell', 'Cable', 'Bodyweight', 'Machine', 'Kettlebell']),
            'instructions' => fake()->sentence(),
        ];
    }
}
