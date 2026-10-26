<?php

namespace Database\Seeders;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a demo coach with clients, templates and a few weeks of training.
     *
     * Coach login:  coach@spotter.test / password
     * Client login: maya@spotter.test  / password
     */
    public function run(): void
    {
        $this->call(ExerciseLibrarySeeder::class);

        $coach = User::factory()->create([
            'name' => 'Sam Rivera',
            'email' => 'coach@spotter.test',
        ]);

        $clients = collect([
            ['name' => 'Maya Chen', 'email' => 'maya@spotter.test', 'goal' => 'First bodyweight pull-up'],
            ['name' => 'Derek Osei', 'email' => 'derek@spotter.test', 'goal' => 'Lose 15 lb before summer'],
            ['name' => 'Priya Nair', 'email' => 'priya@spotter.test', 'goal' => 'Squat 1.5× bodyweight'],
        ])->map(fn (array $attrs) => User::factory()->clientOf($coach)->create($attrs));

        User::factory()->clientOf($coach)->invited()->create([
            'name' => 'Jordan Blake',
            'email' => 'jordan@spotter.test',
            'goal' => 'Get back into training',
        ]);

        $templates = collect([
            'Upper A' => [['Bench Press', 4, '6-8'], ['Barbell Row', 4, '8'], ['Dumbbell Shoulder Press', 3, '10'], ['Lat Pulldown', 3, '10-12'], ['Triceps Pushdown', 3, '12']],
            'Lower A' => [['Back Squat', 4, '5'], ['Romanian Deadlift', 3, '8'], ['Walking Lunge', 3, '10/side'], ['Lying Leg Curl', 3, '12'], ['Plank', 3, '45s']],
            'Conditioning' => [['Kettlebell Swing', 5, '15'], ['Rowing Machine', 5, '250m'], ['Farmer Carry', 4, '40m'], ['Dead Bug', 3, '10/side']],
        ])->map(function (array $exercises, string $title) use ($coach) {
            $template = Workout::create(['coach_id' => $coach->id, 'title' => $title]);

            foreach ($exercises as $position => [$name, $sets, $reps]) {
                $template->items()->create([
                    'exercise_id' => Exercise::where('name', $name)->value('id'),
                    'position' => $position,
                    'sets' => $sets,
                    'reps' => $reps,
                    'rest_seconds' => 90,
                ]);
            }

            return $template;
        })->values();

        // Two weeks of history and one week ahead, Mon/Wed/Fri.
        foreach ($clients as $c => $client) {
            for ($day = -14; $day <= 7; $day++) {
                $date = today()->addDays($day);

                if (! in_array($date->dayOfWeekIso, [1, 3, 5])) {
                    continue;
                }

                $workout = $templates[($day + 14 + $c) % 3]->assignTo($client, $date);

                // Most past workouts are completed; Derek has been slipping lately.
                $completed = $date->lt(today()) && ! ($client->name === 'Derek Osei' && $day > -6);

                if ($completed) {
                    foreach ($workout->items as $item) {
                        $item->update(['logged_sets' => array_fill(0, $item->sets, [
                            'reps' => preg_replace('/\D.*/', '', $item->reps) ?: $item->reps,
                            'load' => null,
                            'done' => true,
                        ])]);
                    }

                    $workout->update([
                        'completed_at' => $date->setTime(18, 30),
                        'client_feedback' => $day === -2 ? 'Felt strong today — bumped the weight on the last set.' : null,
                    ]);
                }
            }
        }
    }
}
