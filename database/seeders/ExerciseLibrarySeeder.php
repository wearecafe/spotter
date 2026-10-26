<?php

namespace Database\Seeders;

use App\Models\Exercise;
use Illuminate\Database\Seeder;

/**
 * The shared exercise library every coach starts with. Safe to run repeatedly
 * (including in production): it only adds exercises that don't exist yet.
 */
class ExerciseLibrarySeeder extends Seeder
{
    /** Also load the open free-exercise-db library (turned off in tests for speed). */
    public static bool $withOpenLibrary = true;

    public const EXERCISES = [
        // name, muscle group, equipment
        ['Back Squat', 'Quads', 'Barbell'],
        ['Front Squat', 'Quads', 'Barbell'],
        ['Goblet Squat', 'Quads', 'Dumbbell'],
        ['Bulgarian Split Squat', 'Quads', 'Dumbbell'],
        ['Walking Lunge', 'Quads', 'Dumbbell'],
        ['Leg Press', 'Quads', 'Machine'],
        ['Leg Extension', 'Quads', 'Machine'],
        ['Conventional Deadlift', 'Hamstrings', 'Barbell'],
        ['Romanian Deadlift', 'Hamstrings', 'Barbell'],
        ['Lying Leg Curl', 'Hamstrings', 'Machine'],
        ['Hip Thrust', 'Glutes', 'Barbell'],
        ['Glute Bridge', 'Glutes', 'Bodyweight'],
        ['Cable Kickback', 'Glutes', 'Cable'],
        ['Standing Calf Raise', 'Calves', 'Machine'],
        ['Bench Press', 'Chest', 'Barbell'],
        ['Incline Dumbbell Press', 'Chest', 'Dumbbell'],
        ['Push-up', 'Chest', 'Bodyweight'],
        ['Cable Fly', 'Chest', 'Cable'],
        ['Pull-up', 'Back', 'Bodyweight'],
        ['Lat Pulldown', 'Back', 'Cable'],
        ['Barbell Row', 'Back', 'Barbell'],
        ['Single-Arm Dumbbell Row', 'Back', 'Dumbbell'],
        ['Seated Cable Row', 'Back', 'Cable'],
        ['Overhead Press', 'Shoulders', 'Barbell'],
        ['Dumbbell Shoulder Press', 'Shoulders', 'Dumbbell'],
        ['Lateral Raise', 'Shoulders', 'Dumbbell'],
        ['Face Pull', 'Shoulders', 'Cable'],
        ['Barbell Curl', 'Biceps', 'Barbell'],
        ['Hammer Curl', 'Biceps', 'Dumbbell'],
        ['Triceps Pushdown', 'Triceps', 'Cable'],
        ['Overhead Triceps Extension', 'Triceps', 'Dumbbell'],
        ['Dip', 'Triceps', 'Bodyweight'],
        ['Plank', 'Core', 'Bodyweight'],
        ['Dead Bug', 'Core', 'Bodyweight'],
        ['Hanging Knee Raise', 'Core', 'Bodyweight'],
        ['Pallof Press', 'Core', 'Cable'],
        ['Kettlebell Swing', 'Full body', 'Kettlebell'],
        ['Farmer Carry', 'Full body', 'Dumbbell'],
        ['Burpee', 'Full body', 'Bodyweight'],
        ['Rowing Machine', 'Cardio', 'Machine'],
        ['Assault Bike', 'Cardio', 'Machine'],
        ['Treadmill Run', 'Cardio', 'Machine'],
        ['World\'s Greatest Stretch', 'Mobility', 'Bodyweight'],
        ['90/90 Hip Switch', 'Mobility', 'Bodyweight'],
    ];

    public function run(): void
    {
        foreach (self::EXERCISES as [$name, $muscleGroup, $equipment]) {
            Exercise::firstOrCreate(
                ['coach_id' => null, 'name' => $name],
                ['muscle_group' => $muscleGroup, 'equipment' => $equipment],
            );
        }

        if (static::$withOpenLibrary) {
            $this->call(FreeExerciseDbSeeder::class);
        }
    }
}
