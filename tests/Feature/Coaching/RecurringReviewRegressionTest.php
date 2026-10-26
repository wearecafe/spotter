<?php

namespace Tests\Feature\Coaching;

use App\Models\Exercise;
use App\Models\Program;
use App\Models\ProgramExercise;
use App\Models\User;
use App\Models\Workout;
use App\Services\ProgramScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regressions for issues found reviewing recurring programs.
 */
class RecurringReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected User $coach;

    protected User $client;

    protected Exercise $squat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(9, 0)); // Monday
        $this->coach = User::factory()->create();
        $this->client = User::factory()->clientOf($this->coach)->create();
        $this->squat = Exercise::factory()->create(['name' => 'Back Squat']);
    }

    protected function scheduler(): ProgramScheduler
    {
        return app(ProgramScheduler::class);
    }

    /**
     * One day a week on $weekday with squats at $load, rule: add load.
     */
    protected function program(string $start, int $weekday, int $weeks = 6, string $load = '100 kg'): Program
    {
        $program = $this->scheduler()->create($this->client, 'Block', $start, $weeks, 1);
        $day = $program->days()->sole();
        $day->update(['weekday' => $weekday]);
        $day->exercises()->create(['exercise_id' => $this->squat->id, 'sets' => 3, 'reps' => '5', 'load' => $load, 'progression' => 'load', 'progression_step' => '2.5 kg']);
        $this->scheduler()->sync($program->fresh());

        return $program->fresh();
    }

    protected function loadIn(Program $program, int $week): ?string
    {
        return $program->workouts()->where('week', $week)->with('items')->first()?->items->first()?->load;
    }

    public function test_reset_never_erases_what_the_client_logged(): void
    {
        $program = $this->program('2026-10-26', 1);
        $session = $program->workouts()->where('week', 1)->with('items')->sole();
        $this->scheduler()->customize($session);
        $session->items->first()->update(['logged_sets' => [['reps' => '5', 'load' => '100 kg', 'done' => true]]]);

        $this->scheduler()->reset($session->fresh());

        $this->assertNotNull($session->fresh()->customized_at, 'A started session stays as it is.');
        $this->assertSame('100 kg', $session->fresh()->items->first()->logged_sets[0]['load']);
    }

    public function test_a_midweek_start_does_not_jump_the_first_load(): void
    {
        // Starts Wednesday; Monday's first session is in week 2 and should still be the starting load.
        $program = $this->program('2026-10-28', 1);

        $this->assertNull($this->loadIn($program, 1));
        $this->assertSame('100 kg', $this->loadIn($program, 2));
        $this->assertSame('102.5 kg', $this->loadIn($program, 3));
    }

    public function test_a_skipped_week_does_not_add_a_step(): void
    {
        $program = $this->program('2026-10-26', 1);
        $this->scheduler()->skip($program->workouts()->where('week', 2)->sole());

        $this->assertSame('100 kg', $this->loadIn($program, 1));
        $this->assertSame('102.5 kg', $this->loadIn($program, 3), 'Week 3 is one step on from week 1, not two.');
    }

    public function test_a_new_starting_load_applies_from_the_next_open_week_not_after_an_edited_one(): void
    {
        $program = $this->program('2026-10-26', 1, 8);
        $this->scheduler()->customize($program->workouts()->where('week', 6)->sole()); // a planned deload

        Livewire::actingAs($this->coach)->test('pages::coach.program', ['program' => $program])
            ->set('rows.'.ProgramExercise::sole()->id.'.load', '105 kg');

        $this->assertSame(1, ProgramExercise::sole()->base_week);
        $this->assertSame('105 kg', $this->loadIn($program, 1));
        $this->assertSame('107.5 kg', $this->loadIn($program, 2));
    }

    public function test_a_lighter_hand_edited_week_does_not_reset_progress(): void
    {
        $program = $this->program('2026-10-19', 1); // week 1 = Oct 19 (past), week 2 = today
        $week1 = $program->workouts()->where('week', 1)->with('items')->sole();
        $this->scheduler()->customize($week1);
        $week1->items->first()->update(['load' => '60 kg', 'logged_sets' => array_fill(0, 3, ['reps' => '5', 'load' => '60 kg', 'done' => true])]);
        $week1->update(['completed_at' => now()->subWeek()]);

        $this->scheduler()->sync($program->fresh());

        $this->assertSame('100 kg', $this->loadIn($program->fresh(), 2), 'Resumes at the planned load, not 62.5 kg built on the 60 kg deload.');
    }

    public function test_moving_the_start_keeps_edited_sessions_on_their_dates(): void
    {
        $program = $this->program('2026-10-26', 1, 4);
        $edited = $program->workouts()->where('week', 2)->sole();
        $this->scheduler()->customize($edited);

        $this->assertNull($this->scheduler()->moveStart($program, '2026-10-19'));
        $program->refresh();

        $this->assertSame('2026-11-02', $edited->fresh()->scheduled_on->toDateString());
        $this->assertSame(3, $edited->fresh()->week);
        $this->assertSame(1, $program->workouts()->whereDate('scheduled_on', '2026-11-02')->count(), 'No duplicate on the same date.');

        $problem = $this->scheduler()->moveStart($program, '2026-11-16');
        $this->assertNotNull($problem);
        $this->assertSame('2026-10-19', $program->fresh()->starts_on->toDateString());
    }

    public function test_migration_renumbers_midweek_programs_and_keeps_repeated_exercises(): void
    {
        $bench = Exercise::factory()->create(['name' => 'Bench Press']);
        // Old style: starts Thursday, 3 days spaced every 2 days (Thu, Sat, Mon), weeks 1-2 generated.
        $programId = DB::table('programs')->insertGetId(['coach_id' => $this->coach->id, 'client_id' => $this->client->id, 'title' => 'Old', 'starts_on' => '2026-10-22', 'planned_weeks' => 4, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1, 2] as $week) {
            foreach ([1 => '2026-10-22', 2 => '2026-10-24', 3 => '2026-10-26'] as $day => $date) {
                $id = DB::table('workouts')->insertGetId(['coach_id' => $this->coach->id, 'client_id' => $this->client->id, 'program_id' => $programId, 'week' => $week, 'day' => $day, 'title' => "Day $day", 'scheduled_on' => now()->parse($date)->addWeeks($week - 1)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
                if ($day === 1) {
                    DB::table('workout_exercises')->insert([
                        ['workout_id' => $id, 'exercise_id' => $bench->id, 'position' => 0, 'sets' => 5, 'reps' => '5', 'load' => '100 kg', 'progression' => 'load', 'created_at' => now(), 'updated_at' => now()],
                        ['workout_id' => $id, 'exercise_id' => $bench->id, 'position' => 1, 'sets' => 3, 'reps' => '10', 'load' => '70 kg', 'progression' => 'none', 'created_at' => now(), 'updated_at' => now()],
                    ]);
                }
            }
        }

        $migration = require database_path('migrations/2026_10_29_000002_create_program_blueprints.php');
        (fn () => $this->convertExistingPrograms())->call($migration);
        $program = Program::find($programId);
        $this->scheduler()->sync($program);

        // Monday (day 3) sessions were in calendar weeks 2 and 3, not 1 and 2.
        $monday = $program->days()->where('position', 3)->sole();
        $this->assertSame([2, 3, 4], Workout::where('program_day_id', $monday->id)->orderBy('week')->pluck('week')->all());
        $this->assertSame(Workout::where('program_id', $programId)->count(), Workout::where('program_id', $programId)->distinct()->count('scheduled_on'), 'No two sessions on one date.');

        // Both bench entries survive as separate blueprint rows and on generated weeks.
        $thursday = $program->days()->where('position', 1)->sole();
        $this->assertSame(['100 kg', '70 kg'], $thursday->exercises()->pluck('load')->all());
        $this->assertSame(2, Workout::where('program_day_id', $thursday->id)->where('week', 3)->sole()->items()->count());
    }

    public function test_exercises_used_only_in_a_blueprint_cannot_be_deleted(): void
    {
        $custom = Exercise::factory()->create(['coach_id' => $this->coach->id, 'name' => 'My Lift']);
        $program = $this->program('2026-10-26', 1);
        $program->days()->sole()->exercises()->create(['exercise_id' => $custom->id, 'sets' => 3]);

        Livewire::actingAs($this->coach)->test('pages::coach.exercises')->call('delete', $custom->id);

        $this->assertNotNull($custom->fresh());
    }
}
