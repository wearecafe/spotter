<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\CheckIn;
use App\Models\Exercise;
use App\Models\Message;
use App\Models\Program;
use App\Models\User;
use App\Models\Workout;
use App\Services\InboxFeed;
use App\Services\Progression;
use Illuminate\Database\Seeder;

/**
 * A coach with a realistic week of client activity, for demos and local development.
 * Not run on deploy. Log in as alex@example.com / password.
 *
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ExerciseLibrarySeeder::class);
        config(['queue.default' => 'sync']); // draft inbox items immediately

        $coach = User::updateOrCreate(['email' => 'alex@example.com'], [
            'name' => 'Alex Rivera',
            'password' => 'password',
            'role' => Role::Coach,
            'email_verified_at' => now(),
            'business_name' => 'North Shore Strength',
            'coaching_languages' => 'both',
            'client_count_band' => '16-50',
            'subdomain' => 'northshore',
            'import_source' => 'trainerize',
            'onboarded_at' => now(),
        ]);

        $client = fn (string $name, string $email, string $goal, string $locale = 'en') => User::updateOrCreate(['email' => $email], [
            'name' => $name, 'role' => Role::Client, 'coach_id' => $coach->id, 'goal' => $goal, 'locale' => $locale,
            'password' => 'password', 'email_verified_at' => now(), 'invitation_accepted_at' => now(),
        ]);

        $maya = $client('Maya Chen', 'maya@example.com', 'First bodyweight pull-up');
        $daniel = $client('Daniel Okafor', 'daniel@example.com', 'Run a sub-25 5K');
        $priya = $client('Priya Raman', 'priya@example.com', 'Build strength after baby');
        $luc = $client('Luc Tremblay', 'luc@example.com', 'Prise de masse', 'fr');
        $sarah = $client('Sarah Kim', 'sarah@example.com', 'Deadlift 2× bodyweight');

        $ex = fn (string $name) => Exercise::whereNull('coach_id')->where('name', $name)->value('id');

        // Maya: a program with week 1 logged and an upcoming week 2.
        $program = Program::create(['coach_id' => $coach->id, 'client_id' => $maya->id, 'title' => 'Strength block', 'starts_on' => today()->startOfWeek()->subWeek(), 'planned_weeks' => 6]);
        app(Progression::class)->start($program, 2);
        [$lower, $upper] = $program->workouts()->get()->all();
        $lower->update(['title' => 'Lower A', 'completed_at' => now()->subDays(5), 'client_feedback' => 'Squats felt strong. Left knee a bit sore on the split squats.']);
        $upper->update(['title' => 'Upper A', 'completed_at' => now()->subDays(3)]);

        $rows = [
            [$lower, 'Back Squat', 4, '5', '75 kg', '8', 'load', array_fill(0, 4, ['reps' => '5', 'load' => '75 kg', 'rpe' => '7.5', 'done' => true])],
            [$lower, 'Romanian Deadlift', 3, '8', '60 kg', '8', 'load', [['reps' => '8', 'load' => '60 kg', 'rpe' => '8', 'done' => true], ['reps' => '8', 'load' => '60 kg', 'rpe' => '9', 'done' => true], ['reps' => '6', 'load' => '60 kg', 'rpe' => '9.5', 'done' => true]]],
            [$lower, 'Bulgarian Split Squat', 3, '8-10', '2 × 16 kg', null, 'reps', array_fill(0, 3, ['reps' => '10', 'load' => '2 × 16 kg', 'rpe' => null, 'done' => true])],
            [$upper, 'Overhead Press', 4, '6', '37.5 kg', '8', 'load', array_fill(0, 4, ['reps' => '6', 'load' => '37.5 kg', 'rpe' => '8', 'done' => true])],
            [$upper, 'Lat Pulldown', 3, '10-12', '45 kg', null, 'reps', array_fill(0, 3, ['reps' => '12', 'load' => '45 kg', 'rpe' => null, 'done' => true])],
            [$upper, 'Plank', 3, '40s', null, null, 'hold', null],
        ];

        foreach ($rows as $i => [$workout, $name, $sets, $reps, $load, $rpe, $rule, $logged]) {
            $workout->items()->create(['exercise_id' => $ex($name), 'position' => $i, 'sets' => $sets, 'reps' => $reps, 'load' => $load, 'target_rpe' => $rpe, 'rest_seconds' => 120, 'progression' => $rule, 'logged_sets' => $logged]);
        }

        // An older squat session, so this week's squat counts as a personal record.
        $older = Workout::create(['coach_id' => $coach->id, 'client_id' => $maya->id, 'title' => 'Assessment', 'scheduled_on' => today()->subWeeks(3), 'completed_at' => now()->subWeeks(3)]);
        $older->items()->create(['exercise_id' => $ex('Back Squat'), 'sets' => 3, 'reps' => '5', 'logged_sets' => array_fill(0, 3, ['reps' => '5', 'load' => '70 kg', 'done' => true])]);

        app(Progression::class)->generateNextWeek($program);
        $program->workouts()->where('week', 2)->get()->each(fn ($w, $i) => $w->update(['scheduled_on' => today()->addDays($i * 2 + 1)]));

        $feed = app(InboxFeed::class);
        $feed->workoutCompleted($lower->fresh());

        // Check-ins.
        $maya->checkIns()->create(['week_of' => today()->startOfWeek()->subWeek(), 'weight' => 64.6, 'sleep_hours' => 7.4, 'avg_steps' => 9100, 'energy' => 4, 'stress' => 2]);
        $feed->checkInSubmitted($maya->checkIns()->create(['week_of' => today()->startOfWeek(), 'weight' => 64.2, 'sleep_hours' => 5.9, 'avg_steps' => 7400, 'energy' => 2, 'stress' => 4, 'notes' => 'Big deadline at work, sleeping badly. Still got both sessions in.']));
        $feed->checkInSubmitted(CheckIn::create(['client_id' => $priya->id, 'week_of' => today()->startOfWeek(), 'weight' => 71.0, 'sleep_hours' => 6.5, 'energy' => 4, 'stress' => 2, 'notes' => 'Felt good! Hips less tight.']));

        // Daniel missed two sessions.
        foreach ([2, 4] as $daysAgo) {
            Workout::create(['coach_id' => $coach->id, 'client_id' => $daniel->id, 'title' => 'Tempo run + core', 'scheduled_on' => today()->subDays($daysAgo)]);
        }
        Workout::create(['coach_id' => $coach->id, 'client_id' => $daniel->id, 'title' => 'Intervals', 'scheduled_on' => today()->addDay()]);
        $feed->scanMissedSessions($coach);

        // Luc writes in French.
        foreach ([[$coach, 'Salut Luc, ta nouvelle séance est prête pour lundi.'], [$luc, 'Merci! Est-ce que je peux faire la séance de jambes mardi au lieu de lundi? Je travaille tard.']] as [$sender, $body]) {
            $message = Message::create(['coach_id' => $coach->id, 'client_id' => $luc->id, 'sender_id' => $sender->id, 'body' => $body, 'created_at' => now()->subHours(3)]);
        }
        $feed->messageReceived($message);

        Workout::create(['coach_id' => $coach->id, 'client_id' => $sarah->id, 'title' => 'Pull day', 'scheduled_on' => today()]);
        User::updateOrCreate(['email' => 'jordan@example.com'], ['name' => 'Jordan Ellis', 'role' => Role::Client, 'coach_id' => $coach->id, 'invitation_token' => 'demo-invite-jordan']);
    }
}
