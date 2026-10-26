<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\CheckIn;
use App\Models\Exercise;
use App\Models\Message;
use App\Models\NutritionLog;
use App\Models\User;
use App\Models\Workout;
use App\Services\InboxFeed;
use App\Services\ProgramScheduler;
use App\Services\TeamManager;
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

        // Maya: a 6-week program, Lower A on Tuesdays and Upper A on Thursdays. Week 1 is logged.
        $scheduler = app(ProgramScheduler::class);
        $program = $scheduler->create($maya, 'Strength block', today()->startOfWeek()->subWeek(), 6, 2);
        [$lowerDay, $upperDay] = $program->days()->get()->all();
        $lowerDay->update(['title' => 'Lower A', 'weekday' => 2, 'notes' => 'Warm up with 5 minutes on the bike, then two light squat sets.']);
        $upperDay->update(['title' => 'Upper A', 'weekday' => 4]);

        $rows = [
            [$lowerDay, 'Back Squat', 4, '5', '75 kg', '8', 'load', array_fill(0, 4, ['reps' => '5', 'load' => '75 kg', 'rpe' => '7.5', 'done' => true])],
            [$lowerDay, 'Romanian Deadlift', 3, '8', '60 kg', '8', 'load', [['reps' => '8', 'load' => '60 kg', 'rpe' => '8', 'done' => true], ['reps' => '8', 'load' => '60 kg', 'rpe' => '9', 'done' => true], ['reps' => '6', 'load' => '60 kg', 'rpe' => '9.5', 'done' => true]]],
            [$lowerDay, 'Bulgarian Split Squat', 3, '8-10', '2 × 16 kg', null, 'reps', array_fill(0, 3, ['reps' => '10', 'load' => '2 × 16 kg', 'rpe' => null, 'done' => true])],
            [$upperDay, 'Overhead Press', 4, '6', '37.5 kg', '8', 'load', array_fill(0, 4, ['reps' => '6', 'load' => '37.5 kg', 'rpe' => '8', 'done' => true])],
            [$upperDay, 'Lat Pulldown', 3, '10-12', '45 kg', null, 'reps', array_fill(0, 3, ['reps' => '12', 'load' => '45 kg', 'rpe' => null, 'done' => true])],
            [$upperDay, 'Plank', 3, '40s', null, null, 'hold', null],
        ];

        $logs = [];
        foreach ($rows as $i => [$day, $name, $sets, $reps, $load, $rpe, $rule, $logged]) {
            $row = $day->exercises()->create(['exercise_id' => $ex($name), 'position' => $i, 'sets' => $sets, 'reps' => $reps, 'load' => $load, 'target_rpe' => $rpe, 'rest_seconds' => 120, 'progression' => $rule]);
            $logs[$row->id] = $logged;
        }

        $scheduler->sync($program->fresh());

        // Week 1 happened: Maya logged everything and finished both sessions.
        $lower = Workout::where('program_day_id', $lowerDay->id)->where('week', 1)->sole();
        $upper = Workout::where('program_day_id', $upperDay->id)->where('week', 1)->sole();
        foreach ([$lower, $upper] as $session) {
            foreach ($session->items as $item) {
                $item->update(['logged_sets' => $logs[$item->program_exercise_id] ?? null]);
            }
        }
        $lower->update(['completed_at' => $lower->scheduled_on->setTime(18, 30), 'client_feedback' => 'Squats felt strong. Left knee a bit sore on the split squats.']);
        $upper->update(['completed_at' => $upper->scheduled_on->setTime(18, 30)]);
        $scheduler->sync($program->fresh());

        // An older squat session, so this week's squat counts as a personal record.
        $older = Workout::create(['coach_id' => $coach->id, 'client_id' => $maya->id, 'title' => 'Assessment', 'scheduled_on' => today()->subWeeks(3), 'completed_at' => now()->subWeeks(3)]);
        $older->items()->create(['exercise_id' => $ex('Back Squat'), 'sets' => 3, 'reps' => '5', 'logged_sets' => array_fill(0, 3, ['reps' => '5', 'load' => '70 kg', 'done' => true])]);

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

        // An ongoing chat with Maya: yesterday's back-and-forth (read) and a fresh question this morning.
        foreach ([
            [$maya, 'Quick one: my gym’s squat rack is taken every Tuesday at 6. Can I swap back squats for something?', 26 * 60, true],
            [$coach, 'Totally. Do goblet squats, 4×8 with the heaviest bell you can hold for all 8. Same tempo.', 25 * 60 + 40, true],
            [$maya, 'Perfect, thank you!', 25 * 60 + 30, true],
            [$coach, 'How did the first pull-up negatives feel? Any elbow soreness?', 24 * 60, true],
            [$maya, 'No soreness! Got 5 slow ones. Lats are definitely feeling it today 😅', 90, false],
            [$maya, 'Also — should I keep going if I sleep under 6 hours? This week has been rough.', 88, false],
        ] as [$sender, $body, $minutesAgo, $read]) {
            Message::create(['coach_id' => $coach->id, 'client_id' => $maya->id, 'sender_id' => $sender->id, 'body' => $body, 'created_at' => now()->subMinutes($minutesAgo), 'read_at' => $read ? now()->subMinutes($minutesAgo - 5) : null, 'notified_at' => now()]);
        }

        Message::create(['coach_id' => $coach->id, 'client_id' => $priya->id, 'sender_id' => $coach->id, 'body' => 'Great check-in Priya. Keep the hip mobility in every warm-up this week.', 'created_at' => now()->subDays(2), 'read_at' => now()->subDays(2)]);

        Workout::create(['coach_id' => $coach->id, 'client_id' => $sarah->id, 'title' => 'Pull day', 'scheduled_on' => today()]);

        // Alex runs a gym team with a second coach, who coaches Daniel.
        $coach->team->update(['name' => 'North Shore Strength', 'plan' => 'team']);
        $sam = User::firstOrNew(['email' => 'sam@example.com']);
        $sam->forceFill(['name' => 'Sam Patel', 'password' => 'password', 'role' => Role::Coach, 'email_verified_at' => now(), 'team_id' => $coach->team_id, 'team_role' => 'coach', 'onboarded_at' => now(), 'last_seen_at' => now()->subHours(3)])->save();
        app(TeamManager::class)->reassign($daniel->fresh(), $sam);

        // Two weeks of Maya's nutrition, imported from MacroFactor, against targets.
        $maya->update(['calorie_target' => 2000, 'protein_target' => 130, 'carbs_target' => 220, 'fat_target' => 65]);
        foreach (range(13, 0) as $i => $daysAgo) {
            NutritionLog::updateOrCreate(['client_id' => $maya->id, 'logged_on' => today()->subDays($daysAgo)], [
                'weight' => $daysAgo % 2 === 0 ? round(64.8 - $i * 0.05, 1) : null,
                'calories' => [1980, 2050, 1890, 2210, 1950, 2400, 2010, 1930, 2080, 1700, 1990, 2020, 1880, 2060][$i],
                'protein' => [128, 135, 120, 110, 131, 95, 129, 133, 126, 102, 130, 138, 125, 132][$i],
                'carbs' => [220, 230, 205, 260, 215, 290, 225, 210, 235, 180, 218, 222, 207, 228][$i],
                'fat' => [64, 66, 61, 75, 63, 82, 65, 62, 68, 55, 64, 63, 60, 66][$i],
                'source' => $daysAgo > 2 ? 'macrofactor' : 'manual',
            ]);
        }

        // Clients and coaches seen recently, for the backoffice.
        User::whereIn('id', [$maya->id, $luc->id, $priya->id])->update(['last_seen_at' => now()->subHours(2)]);
        $coach->forceFill(['last_seen_at' => now()])->save();

        // A second tenant, and a Spotter staff account for the backoffice (staff@example.com / password).
        $solo = User::firstOrCreate(['email' => 'jordan.coach@example.com'], ['name' => 'Jordan Ellis Coaching', 'password' => 'password', 'role' => Role::Coach, 'email_verified_at' => now()]);
        User::factory()->count(4)->create(['role' => Role::Client, 'coach_id' => $solo->id, 'invitation_accepted_at' => now()]);
        $staff = User::firstOrCreate(['email' => 'staff@example.com'], ['name' => 'Spotter Support', 'password' => 'password', 'role' => Role::Coach, 'email_verified_at' => now()]);
        $staff->forceFill(['is_staff' => true])->save();
        User::updateOrCreate(['email' => 'jordan@example.com'], ['name' => 'Jordan Ellis', 'role' => Role::Client, 'coach_id' => $coach->id, 'invitation_token' => 'demo-invite-jordan']);
    }
}
