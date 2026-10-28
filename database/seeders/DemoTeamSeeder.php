<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\CheckIn;
use App\Models\Exercise;
use App\Models\Message;
use App\Models\NutritionLog;
use App\Models\Program;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\Workout;
use App\Services\InboxFeed;
use App\Services\ProgramScheduler;
use App\Support\Demo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * North Shore Strength: the fictional demo gym behind /demo. An owner, an
 * admin coach and a coach, nine clients with programs, check-ins, nutrition
 * and chat, all dated relative to today. Safe to run in production: it only
 * ever creates the demo team, on the reserved northshorestrength.example
 * domain, and refuses to run if one already exists.
 *
 * Rebuild it with `php artisan spotter:demo:reset` (the scheduler does this nightly).
 */
class DemoTeamSeeder extends Seeder
{
    /**
     * The accounts /demo offers, in order.
     *
     * @var list<array{key: string, name: string, title: string, description: string}>
     */
    public const ACCOUNTS = [
        ['key' => 'alex', 'name' => 'Alex Rivera', 'title' => 'Gym owner', 'description' => 'Runs North Shore Strength. Sees every client, the Coach Inbox with drafted replies, programs, messages and the team.'],
        ['key' => 'sam', 'name' => 'Sam Patel', 'title' => 'Coach', 'description' => 'One of Alex’s coaches. Sees only their own clients, including one who has missed sessions this week.'],
        ['key' => 'maya', 'name' => 'Maya Chen', 'title' => 'Client', 'description' => 'Two weeks into a strength block. Log today’s workout, send a check-in, track nutrition and chat with Alex.'],
        ['key' => 'luc', 'name' => 'Luc Tremblay', 'title' => 'Client, in French', 'description' => 'The client app in French, with a program and a conversation with his coach.'],
    ];

    public const TEAM_NAME = 'North Shore Strength';

    public static function email(string $key): string
    {
        return $key.'@'.Demo::DOMAIN;
    }

    /** Draft the inbox with Claude when it's configured (the nightly rebuild); /demo's first-visit build doesn't wait on it. */
    public bool $useAi = true;

    protected ProgramScheduler $scheduler;

    protected InboxFeed $feed;

    public function run(): void
    {
        if (Demo::teamId() !== null) {
            throw new RuntimeException('The demo team already exists. Rebuild it with `php artisan spotter:demo:reset`.');
        }

        $this->scheduler = app(ProgramScheduler::class);
        $this->feed = app(InboxFeed::class);

        // Draft inbox items while building, so the inbox is ready on the first visit.
        $queue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            Demo::seeding(fn () => Model::unguarded(fn () => $this->build()), $this->useAi);
        } finally {
            config(['queue.default' => $queue]);
        }
    }

    protected function build(): void
    {
        // Production loads the library on migrate; this covers a fresh local database.
        if (! Exercise::query()->whereNull('coach_id')->exists()) {
            $this->callSilent(ExerciseLibrarySeeder::class);
        }

        // Coaches. Alex owns the team; Jess is an admin; Sam coaches their own clients.
        $alex = $this->coach('alex', 'Alex Rivera', 'owner', [
            'business_name' => self::TEAM_NAME,
            'coaching_languages' => 'both',
            'client_count_band' => '16-50',
            'import_source' => 'trainerize',
        ]);
        $alex->team->forceFill(['name' => self::TEAM_NAME, 'plan' => 'team', 'is_demo' => true])->save();
        $jess = $this->coach('jess', 'Jess Moreau', 'admin', ['coaching_languages' => 'both']);
        $sam = $this->coach('sam', 'Sam Patel', 'coach');

        TeamInvitation::create([
            'team_id' => $alex->team_id, 'invited_by' => $alex->id, 'email' => self::email('riley'), 'role' => 'coach',
            'token' => Str::random(48), 'expires_at' => now()->addDays(12), 'created_at' => now()->subDays(2),
        ]);

        $this->templates($alex);

        // Alex's clients.
        $maya = $this->client('maya', 'Maya Chen', $alex, 'First bodyweight pull-up');
        $priya = $this->client('priya', 'Priya Raman', $alex, 'Build strength after baby');
        $luc = $this->client('luc', 'Luc Tremblay', $alex, 'Prise de masse', 'fr');
        $sarah = $this->client('sarah', 'Sarah Kim', $alex, 'Deadlift 2× bodyweight');
        $this->client('noah', 'Noah Williams', $alex, 'Get back into training', 'en', joined: false);

        // Sam's clients.
        $daniel = $this->client('daniel', 'Daniel Okafor', $sam, 'Run a sub-25 5K');
        $emma = $this->client('emma', 'Emma Johansson', $sam, 'Add size to legs and glutes');
        $omar = $this->client('omar', 'Omar Haddad', $sam, 'Lose 10 kg, keep strength');

        // Jess's client.
        $chloe = $this->client('chloe', 'Chloé Gagnon', $jess, 'Courir un demi-marathon', 'fr');

        $this->maya($alex, $maya);
        $this->otherPrograms($priya, $luc, $emma, $omar, $chloe);
        $this->sarah($alex, $sarah);
        $this->daniel($sam, $daniel);
        $this->conversations($alex, $sam, $maya, $priya, $luc, $emma);
        $this->nutrition($maya, $emma);

        foreach ([$alex, $jess, $sam] as $coach) {
            $this->feed->scanMissedSessions($coach);
        }

        // Recent activity, for the dashboards and the backoffice.
        User::whereIn('id', [$maya->id, $luc->id, $priya->id, $emma->id])->update(['last_seen_at' => now()->subHours(2)]);
        User::whereIn('id', [$sarah->id, $omar->id, $chloe->id])->update(['last_seen_at' => now()->subDays(1)]);
        $sam->forceFill(['last_seen_at' => now()->subHours(3)])->save();
        $jess->forceFill(['last_seen_at' => now()->subDay()])->save();
        $alex->forceFill(['last_seen_at' => now()])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function coach(string $key, string $name, string $teamRole, array $attributes = []): User
    {
        $owner = User::query()->where('email', self::email('alex'))->first();

        $coach = new User;
        $coach->forceFill([
            'name' => $name,
            'email' => self::email($key),
            // Demo accounts are only entered through /demo; nobody knows this.
            'password' => Str::random(40),
            'role' => Role::Coach,
            'email_verified_at' => now(),
            'onboarded_at' => now()->subMonths(4),
            'created_at' => now()->subMonths(4),
            'team_id' => $teamRole === 'owner' ? null : $owner->team_id,
            'team_role' => $teamRole === 'owner' ? null : $teamRole,
            ...$attributes,
        ])->save();

        return $coach->fresh();
    }

    protected function client(string $key, string $name, User $coach, string $goal, string $locale = 'en', bool $joined = true): User
    {
        $client = new User;
        $client->forceFill([
            'name' => $name,
            'email' => self::email($key),
            'password' => $joined ? Str::random(40) : null,
            'role' => Role::Client,
            'coach_id' => $coach->id,
            'team_id' => $coach->team_id,
            'goal' => $goal,
            'locale' => $locale,
            'email_verified_at' => $joined ? now() : null,
            'invitation_token' => Str::random(40),
            'invited_at' => now()->subWeeks($joined ? 6 : 0)->subDays($joined ? 0 : 1),
            'invitation_accepted_at' => $joined ? now()->subWeeks(6) : null,
            'created_at' => now()->subWeeks(6),
        ])->save();

        return $client;
    }

    protected function exercise(string $name): int
    {
        return Exercise::query()->whereNull('coach_id')->where('name', $name)->value('id')
            ?? throw new RuntimeException("Exercise '{$name}' is not in the library.");
    }

    /**
     * Team workout templates, shared by every coach at North Shore Strength.
     */
    protected function templates(User $owner): void
    {
        $templates = [
            'Upper A' => [['Bench Press', 4, '6-8'], ['Barbell Row', 4, '8'], ['Dumbbell Shoulder Press', 3, '10'], ['Lat Pulldown', 3, '10-12'], ['Triceps Pushdown', 3, '12']],
            'Lower A' => [['Back Squat', 4, '5'], ['Romanian Deadlift', 3, '8'], ['Walking Lunge', 3, '10/side'], ['Lying Leg Curl', 3, '12'], ['Plank', 3, '45s']],
            'Conditioning' => [['Kettlebell Swing', 5, '15'], ['Rowing Machine', 5, '250m'], ['Farmer Carry', 4, '40m'], ['Dead Bug', 3, '10/side']],
            'Pull day' => [['Conventional Deadlift', 4, '4'], ['Pull-up', 4, '5'], ['Seated Cable Row', 3, '10'], ['Face Pull', 3, '15'], ['Hanging Leg Raise', 3, '10']],
        ];

        foreach ($templates as $title => $rows) {
            $template = Workout::create(['coach_id' => $owner->id, 'title' => $title]);

            foreach ($rows as $position => [$name, $sets, $reps]) {
                $template->items()->create(['exercise_id' => $this->exercise($name), 'position' => $position, 'sets' => $sets, 'reps' => $reps, 'rest_seconds' => 90]);
            }
        }
    }

    /**
     * Maya: a 6-week program, Lower A on Tuesdays and Upper A on Thursdays. Last week is logged.
     */
    protected function maya(User $coach, User $maya): void
    {
        $program = $this->scheduler->create($maya, 'Strength block', today()->startOfWeek()->subWeek(), 6, 2);
        [$lowerDay, $upperDay] = $program->days()->get()->all();
        $lowerDay->update(['title' => 'Lower A', 'weekday' => 2, 'notes' => 'Warm up with 5 minutes on the bike, then two light squat sets.']);
        $upperDay->update(['title' => 'Upper A', 'weekday' => 4]);

        $rows = [
            [$lowerDay, 'Back Squat', 4, '5', '75 kg', '8', 'load', array_fill(0, 4, ['reps' => '5', 'load' => '75 kg', 'rpe' => '7.5', 'done' => true])],
            [$lowerDay, 'Romanian Deadlift', 3, '8', '60 kg', '8', 'load', [['reps' => '8', 'load' => '60 kg', 'rpe' => '8', 'done' => true], ['reps' => '8', 'load' => '60 kg', 'rpe' => '9', 'done' => true], ['reps' => '6', 'load' => '60 kg', 'rpe' => '9.5', 'done' => true]]],
            [$lowerDay, 'Bulgarian Split Squat', 3, '8-10', '2 × 16 kg', null, 'reps', array_fill(0, 3, ['reps' => '10', 'load' => '2 × 16 kg', 'rpe' => null, 'done' => true])],
            [$upperDay, 'Overhead Press', 4, '6', '37.5 kg', '8', 'load', array_fill(0, 4, ['reps' => '6', 'load' => '37.5 kg', 'rpe' => '8', 'done' => true])],
            [$upperDay, 'Pull-up', 4, '3', 'Band assist', null, 'reps', array_fill(0, 4, ['reps' => '3', 'load' => 'Band assist', 'rpe' => null, 'done' => true])],
            [$upperDay, 'Lat Pulldown', 3, '10-12', '45 kg', null, 'reps', array_fill(0, 3, ['reps' => '12', 'load' => '45 kg', 'rpe' => null, 'done' => true])],
            [$upperDay, 'Plank', 3, '40s', null, null, 'hold', null],
        ];

        $logs = [];
        foreach ($rows as $i => [$day, $name, $sets, $reps, $load, $rpe, $rule, $logged]) {
            $row = $day->exercises()->create(['exercise_id' => $this->exercise($name), 'position' => $i, 'sets' => $sets, 'reps' => $reps, 'load' => $load, 'target_rpe' => $rpe, 'rest_seconds' => 120, 'progression' => $rule]);
            $logs[$row->id] = $logged;
        }

        $this->scheduler->sync($program->fresh());

        $lower = Workout::where('program_day_id', $lowerDay->id)->where('week', 1)->sole();
        $upper = Workout::where('program_day_id', $upperDay->id)->where('week', 1)->sole();
        foreach ([$lower, $upper] as $session) {
            foreach ($session->items as $item) {
                $item->update(['logged_sets' => $logs[$item->program_exercise_id] ?? null]);
            }
        }
        $lower->update(['completed_at' => $lower->scheduled_on->setTime(18, 30), 'client_feedback' => 'Squats felt strong. Left knee a bit sore on the split squats.']);
        $upper->update(['completed_at' => $upper->scheduled_on->setTime(18, 30)]);
        $this->scheduler->sync($program->fresh());

        // Whatever day the demo is rebuilt on, she's kept up this week too: today's session or the next one is still to do.
        $thisWeek = Workout::query()->where('program_id', $program->id)->where('week', 2)->where('scheduled_on', '<', today())->with('items')->get();
        foreach ($thisWeek as $session) {
            $this->logAsPrescribed($session, 18);
        }
        $this->scheduler->sync($program->fresh());

        // An older squat session, so last week's squat counts as a personal record.
        $older = Workout::create(['coach_id' => $coach->id, 'client_id' => $maya->id, 'title' => 'Assessment', 'scheduled_on' => today()->subWeeks(3), 'completed_at' => now()->subWeeks(3)]);
        $older->items()->create(['exercise_id' => $this->exercise('Back Squat'), 'sets' => 3, 'reps' => '5', 'logged_sets' => array_fill(0, 3, ['reps' => '5', 'load' => '70 kg', 'done' => true])]);

        $this->feed->workoutCompleted($lower->fresh());

        $maya->checkIns()->create(['week_of' => today()->startOfWeek()->subWeek(), 'weight' => 64.6, 'sleep_hours' => 7.4, 'avg_steps' => 9100, 'energy' => 4, 'stress' => 2, 'created_at' => now()->subWeek()]);
        $this->feed->checkInSubmitted($maya->checkIns()->create(['week_of' => today()->startOfWeek(), 'weight' => 64.2, 'sleep_hours' => 5.9, 'avg_steps' => 7400, 'energy' => 2, 'stress' => 4, 'notes' => 'Big deadline at work, sleeping badly. Still got both sessions in.']));
    }

    /**
     * Programs for the rest of the team's clients, with weeks already logged and progressed.
     */
    protected function otherPrograms(User $priya, User $luc, User $emma, User $omar, User $chloe): void
    {
        $this->program($priya, 'Return to lifting', 3, 8, [
            ['Full body A', 1, [['Goblet Squat', 3, '10', '16 kg', 'load'], ['Dumbbell Bench Press', 3, '10', '12 kg', 'load'], ['Seated Cable Row', 3, '12', '30 kg', 'reps'], ['Dead Bug', 3, '8/side', null, 'hold']]],
            ['Full body B', 4, [['Hip Thrust', 3, '10', '50 kg', 'load'], ['Push-up', 3, '8', 'Incline', 'reps'], ['Lat Pulldown', 3, '10', '35 kg', 'reps'], ['Farmer Carry', 3, '30m', '2 × 16 kg', 'hold']]],
        ], fn (int $week, int $day) => true);
        $this->feed->checkInSubmitted(CheckIn::create(['client_id' => $priya->id, 'week_of' => today()->startOfWeek(), 'weight' => 71.0, 'sleep_hours' => 6.5, 'energy' => 4, 'stress' => 2, 'notes' => 'Felt good! Hips less tight.']));

        $this->program($luc, 'Prise de masse', 4, 10, [
            ['Haut du corps', 1, [['Bench Press', 4, '6-8', '80 kg', 'load'], ['Barbell Row', 4, '8', '70 kg', 'load'], ['Incline Dumbbell Press', 3, '10', '26 kg', 'reps'], ['Face Pull', 3, '15', '20 kg', 'reps']]],
            ['Bas du corps', 3, [['Back Squat', 4, '6', '100 kg', 'load'], ['Romanian Deadlift', 3, '8', '90 kg', 'load'], ['Leg Press', 3, '12', '160 kg', 'reps'], ['Hanging Leg Raise', 3, '10', null, 'reps']]],
            ['Complet', 5, [['Conventional Deadlift', 3, '5', '140 kg', 'load'], ['Overhead Press', 3, '6', '50 kg', 'load'], ['Pull-up', 3, '8', null, 'reps']]],
        ], fn (int $week, int $day) => ! ($week === 3 && $day === 2));

        $this->program($emma, 'Hypertrophy, 8 weeks', 4, 8, [
            ['Lower 1', 1, [['Back Squat', 4, '8', '70 kg', 'load'], ['Hip Thrust', 4, '10', '90 kg', 'load'], ['Walking Lunge', 3, '10/side', '2 × 14 kg', 'reps'], ['Lying Leg Curl', 3, '12', '30 kg', 'reps']]],
            ['Upper 1', 2, [['Incline Dumbbell Press', 4, '10', '18 kg', 'load'], ['Seated Cable Row', 4, '10', '45 kg', 'reps'], ['Dumbbell Shoulder Press', 3, '12', '12 kg', 'reps'], ['Face Pull', 3, '15', '15 kg', 'reps']]],
            ['Lower 2', 4, [['Front Squat', 4, '6', '55 kg', 'load'], ['Romanian Deadlift', 4, '8', '65 kg', 'load'], ['Leg Press', 3, '15', '120 kg', 'reps'], ['Plank', 3, '45s', null, 'hold']]],
            ['Upper 2', 5, [['Bench Press', 4, '8', '50 kg', 'load'], ['Lat Pulldown', 4, '10', '45 kg', 'reps'], ['Triceps Pushdown', 3, '12', '20 kg', 'reps']]],
        ], fn (int $week, int $day) => ! ($week === 2 && $day === 3), feedback: 'Hip thrusts felt awkward on the bench today. Could you look at my setup?');

        $this->program($omar, 'Recomp', 2, 12, [
            ['Strength', 1, [['Trap Bar Deadlift', 4, '5', '120 kg', 'load'], ['Bench Press', 4, '6', '85 kg', 'load'], ['Pull-up', 3, '6', null, 'reps']]],
            ['Conditioning', 3, [['Kettlebell Swing', 5, '15', '24 kg', 'reps'], ['Sled Push', 6, '20m', '80 kg', 'hold'], ['Rowing Machine', 4, '500m', null, 'hold']]],
            ['Strength 2', 5, [['Front Squat', 4, '5', '90 kg', 'load'], ['Overhead Press', 4, '6', '55 kg', 'load'], ['Barbell Row', 3, '8', '75 kg', 'load']]],
        ], fn (int $week, int $day) => $week === 1 || $day === 0);

        $this->program($chloe, 'Base de course + force', 1, 12, [
            ['Force', 2, [['Goblet Squat', 3, '10', '20 kg', 'load'], ['Romanian Deadlift', 3, '10', '40 kg', 'load'], ['Bulgarian Split Squat', 3, '8', '2 × 10 kg', 'reps']]],
            ['Gainage', 4, [['Dead Bug', 3, '10/side', null, 'reps'], ['Plank', 3, '45s', null, 'hold'], ['Farmer Carry', 3, '40m', '2 × 16 kg', 'hold']]],
        ], fn (int $week, int $day) => true);
    }

    /**
     * Create a program, fill its days, then log each past week in order so
     * progression steps up from what was actually lifted.
     *
     * @param  list<array{0: string, 1: int, 2: list<array{0: string, 1: int, 2: string, 3: ?string, 4: string}>}>  $days
     * @param  callable(int, int): bool  $completed  Whether the session in that week and day was done.
     */
    protected function program(User $client, string $title, int $startedWeeksAgo, int $weeks, array $days, callable $completed, ?string $feedback = null): Program
    {
        $program = $this->scheduler->create($client, $title, today()->startOfWeek()->subWeeks($startedWeeksAgo), $weeks, count($days));

        foreach ($program->days()->orderBy('position')->get()->values() as $i => $day) {
            [$dayTitle, $weekday, $rows] = $days[$i];
            $day->update(['title' => $dayTitle, 'weekday' => $weekday]);

            foreach ($rows as $position => [$name, $sets, $reps, $load, $rule]) {
                $day->exercises()->create(['exercise_id' => $this->exercise($name), 'position' => $position, 'sets' => $sets, 'reps' => $reps, 'load' => $load, 'rest_seconds' => 90, 'progression' => $rule]);
            }
        }

        $this->scheduler->sync($program->fresh());
        $dayIds = $program->days()->orderBy('position')->pluck('id')->values();
        $lastCompleted = null;

        for ($week = 1; $week <= $startedWeeksAgo + 1; $week++) {
            $sessions = Workout::query()->where('program_id', $program->id)->where('week', $week)->where('scheduled_on', '<', today())->with('items')->get();

            foreach ($sessions as $session) {
                if (! $completed($week, $dayIds->search($session->program_day_id))) {
                    continue;
                }

                $this->logAsPrescribed($session, 7);
                $lastCompleted = $session;
            }

            $this->scheduler->sync($program->fresh());
        }

        if ($feedback !== null && $lastCompleted !== null) {
            $lastCompleted->update(['client_feedback' => $feedback, 'reported_at' => null]);
            $this->feed->workoutCompleted($lastCompleted->fresh());
        }

        return $program;
    }

    /**
     * Mark a session done with every set logged as written (the leading number of a rep range).
     */
    protected function logAsPrescribed(Workout $session, int $hour): void
    {
        foreach ($session->items as $item) {
            $reps = preg_match('/^\d+/', (string) $item->reps, $m) ? $m[0] : (string) $item->reps;
            $item->update(['logged_sets' => array_fill(0, (int) $item->sets, ['reps' => $reps, 'load' => $item->load, 'rpe' => null, 'done' => true])]);
        }

        $session->update(['completed_at' => $session->scheduled_on->setTime($hour, 15), 'reported_at' => now()]);
    }

    /**
     * Sarah follows team templates on Mon/Wed/Fri, with Pull day today.
     */
    protected function sarah(User $coach, User $sarah): void
    {
        $templates = Workout::query()->templates()->where('coach_id', $coach->id)->get()->keyBy('title');
        $rotation = ['Upper A', 'Lower A', 'Conditioning'];

        foreach (range(-14, -1) as $i => $offset) {
            $date = today()->addDays($offset);

            if (! in_array($date->dayOfWeekIso, [1, 3, 5], true)) {
                continue;
            }

            $this->logAsPrescribed($templates[$rotation[$i % 3]]->assignTo($sarah, $date), 12);
        }

        $templates['Pull day']->assignTo($sarah, today());
    }

    /**
     * Daniel missed two sessions this week; the morning scan flags him in Sam's inbox.
     */
    protected function daniel(User $coach, User $daniel): void
    {
        foreach ([9, 7] as $daysAgo) {
            Workout::create(['coach_id' => $coach->id, 'client_id' => $daniel->id, 'title' => 'Tempo run + core', 'scheduled_on' => today()->subDays($daysAgo), 'completed_at' => today()->subDays($daysAgo)->setTime(6, 40), 'reported_at' => now()]);
        }
        foreach ([4, 2] as $daysAgo) {
            Workout::create(['coach_id' => $coach->id, 'client_id' => $daniel->id, 'title' => 'Tempo run + core', 'scheduled_on' => today()->subDays($daysAgo)]);
        }
        Workout::create(['coach_id' => $coach->id, 'client_id' => $daniel->id, 'title' => 'Intervals', 'scheduled_on' => today()->addDay()]);
    }

    protected function conversations(User $alex, User $sam, User $maya, User $priya, User $luc, User $emma): void
    {
        $say = fn (User $coach, User $client, User $sender, string $body, int $minutesAgo, bool $read) => Message::create([
            'coach_id' => $coach->id, 'client_id' => $client->id, 'sender_id' => $sender->id, 'body' => $body,
            'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()->subMinutes($minutesAgo),
            'read_at' => $read ? now()->subMinutes(max(0, $minutesAgo - 5)) : null,
            // Already "emailed", so the unread-message emailer leaves the demo alone.
            'notified_at' => now(),
        ]);

        // Maya: yesterday's back-and-forth (read) and a fresh question this morning.
        $say($alex, $maya, $maya, 'Quick one: my gym’s squat rack is taken every Tuesday at 6. Can I swap back squats for something?', 26 * 60, true);
        $say($alex, $maya, $alex, 'Totally. Do goblet squats, 4×8 with the heaviest bell you can hold for all 8. Same tempo.', 25 * 60 + 40, true);
        $say($alex, $maya, $maya, 'Perfect, thank you!', 25 * 60 + 30, true);
        $say($alex, $maya, $alex, 'How did the first pull-up negatives feel? Any elbow soreness?', 24 * 60, true);
        $say($alex, $maya, $maya, 'No soreness! Got 5 slow ones. Lats are definitely feeling it today 😅', 90, false);
        $this->feed->messageReceived($say($alex, $maya, $maya, 'Also — should I keep going if I sleep under 6 hours? This week has been rough.', 88, false));

        // Luc writes in French.
        $say($alex, $luc, $alex, 'Salut Luc, ta nouvelle séance est prête pour lundi.', 4 * 60, true);
        $this->feed->messageReceived($say($alex, $luc, $luc, 'Merci! Est-ce que je peux faire la séance de jambes mardi au lieu de lundi? Je travaille tard.', 3 * 60, false));

        $say($alex, $priya, $alex, 'Great check-in Priya. Keep the hip mobility in every warm-up this week.', 2 * 24 * 60, true);
        $say($alex, $priya, $priya, 'Will do, thanks Alex!', 2 * 24 * 60 - 30, true);

        $say($sam, $emma, $sam, 'Nice week Emma. We bump hip thrusts by 5 kg on Monday.', 3 * 24 * 60, true);
        $say($sam, $emma, $emma, 'Let’s go 💪', 3 * 24 * 60 - 12, true);
    }

    /**
     * Two weeks of Maya's nutrition, imported from MacroFactor, against targets. Emma logs by hand.
     */
    protected function nutrition(User $maya, User $emma): void
    {
        $maya->update(['calorie_target' => 2000, 'protein_target' => 130, 'carbs_target' => 220, 'fat_target' => 65]);
        foreach (range(13, 0) as $i => $daysAgo) {
            NutritionLog::create([
                'client_id' => $maya->id,
                'logged_on' => today()->subDays($daysAgo),
                'weight' => $daysAgo % 2 === 0 ? round(64.8 - $i * 0.05, 1) : null,
                'calories' => [1980, 2050, 1890, 2210, 1950, 2400, 2010, 1930, 2080, 1700, 1990, 2020, 1880, 2060][$i],
                'protein' => [128, 135, 120, 110, 131, 95, 129, 133, 126, 102, 130, 138, 125, 132][$i],
                'carbs' => [220, 230, 205, 260, 215, 290, 225, 210, 235, 180, 218, 222, 207, 228][$i],
                'fat' => [64, 66, 61, 75, 63, 82, 65, 62, 68, 55, 64, 63, 60, 66][$i],
                'source' => $daysAgo > 2 ? 'macrofactor' : 'manual',
            ]);
        }

        $emma->update(['calorie_target' => 2400, 'protein_target' => 140]);
        foreach (range(6, 1) as $i => $daysAgo) {
            NutritionLog::create([
                'client_id' => $emma->id,
                'logged_on' => today()->subDays($daysAgo),
                'calories' => [2350, 2480, 2290, 2510, 2400, 2330][$i],
                'protein' => [138, 145, 129, 151, 140, 136][$i],
                'source' => 'manual',
            ]);
        }
    }
}
