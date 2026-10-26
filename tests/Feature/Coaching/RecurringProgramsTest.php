<?php

namespace Tests\Feature\Coaching;

use App\Models\Exercise;
use App\Models\Program;
use App\Models\ProgramDay;
use App\Models\User;
use App\Models\Workout;
use App\Services\ProgramScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class RecurringProgramsTest extends TestCase
{
    use RefreshDatabase;

    protected User $coach;

    protected User $client;

    protected Exercise $squat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(9, 0)); // a Monday
        $this->coach = User::factory()->create();
        $this->client = User::factory()->clientOf($this->coach)->create(['name' => 'Maya Chen']);
        $this->squat = Exercise::factory()->create(['name' => 'Back Squat']);
    }

    /**
     * A 4-week, 2-day program (Mon + Thu) starting today, with squats on day A.
     */
    protected function program(string $start = '2026-10-26', int $weeks = 4): Program
    {
        Livewire::actingAs($this->coach)->test('pages::coach.clients.show', ['client' => $this->client])
            ->set('programTitle', 'Strength block')
            ->set('programStart', $start)
            ->set('programWeeks', $weeks)
            ->set('programDays', 2)
            ->call('createProgram')
            ->assertHasNoErrors();

        $program = Program::sole();

        $page = Livewire::actingAs($this->coach)->test('pages::coach.program', ['program' => $program]);
        $dayA = $program->days()->first();
        $page->call('openPicker', $dayA->id)->call('addExercise', $this->squat->id);
        $row = $dayA->exercises()->sole();
        $page->set("rows.{$row->id}.sets", '3')->set("rows.{$row->id}.reps", '5')->set("rows.{$row->id}.load", '75 kg');

        return $program->fresh();
    }

    protected function sessionAt(Program $program, int $week, int $day = 1): Workout
    {
        return $program->workouts()->where('week', $week)->where('day', $day)->with('items')->sole();
    }

    protected function complete(Workout $session, array $sets): void
    {
        $item = $session->items->first();
        $item->update(['logged_sets' => $sets]);

        Livewire::actingAs($this->client)->test('pages::training.workout', ['workout' => $session->fresh()])->call('complete');
    }

    public function test_creating_a_program_schedules_every_week_on_its_weekdays(): void
    {
        $program = $this->program();

        $this->assertSame(['Day A', 'Day B'], $program->days()->pluck('title')->all());
        $this->assertSame([1, 4], $program->days()->pluck('weekday')->all());
        $this->assertSame(8, $program->workouts()->count());
        $this->assertSame('2026-10-26', $this->sessionAt($program, 1, 1)->scheduled_on->toDateString());
        $this->assertSame('2026-10-29', $this->sessionAt($program, 1, 2)->scheduled_on->toDateString());
        $this->assertSame('2026-11-16', $this->sessionAt($program, 4, 1)->scheduled_on->toDateString());

        $this->actingAs($this->client)->get(route('training.index'))->assertSee('Day A');
    }

    public function test_a_mid_week_start_skips_days_before_it(): void
    {
        $program = $this->program('2026-10-28'); // Wednesday: no Monday session in week 1

        $this->assertFalse($program->workouts()->where('week', 1)->where('day', 1)->exists());
        $this->assertTrue($program->workouts()->where('week', 1)->where('day', 2)->exists());
    }

    public function test_one_blueprint_edit_reaches_every_week_with_planned_progression(): void
    {
        $program = $this->program();

        $this->assertSame(['75 kg', '77.5 kg', '80 kg', '82.5 kg'], collect(range(1, 4))->map(fn ($w) => $this->sessionAt($program, $w)->items->first()->load)->all());
        $this->assertSame(['load'], $this->sessionAt($program, 2)->items->first()->changed_fields);
    }

    public function test_completing_a_session_replans_the_following_weeks_from_what_was_lifted(): void
    {
        $program = $this->program();

        // Week 1 missed reps: week 2 holds at 75, week 3 plans 77.5.
        $this->complete($this->sessionAt($program, 1), [['reps' => '5', 'load' => '75 kg', 'done' => true], ['reps' => '5', 'load' => '75 kg', 'done' => true], ['reps' => '3', 'load' => '75 kg', 'done' => true]]);

        $this->assertSame('75 kg', $this->sessionAt($program, 2)->items->first()->load);
        $this->assertStringContainsString('Held', $this->sessionAt($program, 2)->items->first()->change_reason);
        $this->assertSame('77.5 kg', $this->sessionAt($program, 3)->items->first()->load);

        // Week 2 hit: week 3 progresses from 75 to 77.5 for real.
        $this->complete($this->sessionAt($program, 2), array_fill(0, 3, ['reps' => '5', 'load' => '75 kg', 'done' => true]));
        $this->assertSame('77.5 kg', $this->sessionAt($program, 3)->items->first()->load);
        $this->assertStringContainsString('All sets hit', $this->sessionAt($program, 3)->items->first()->change_reason);
    }

    public function test_a_missed_week_holds_the_next_one(): void
    {
        $program = $this->program();
        $this->travel(8)->days(); // week 1 Monday passed without a session logged

        app(ProgramScheduler::class)->sync($program);

        $this->assertSame('75 kg', $this->sessionAt($program, 2)->items->first()->load);
    }

    public function test_editing_the_blueprint_changes_upcoming_sessions_but_not_logged_or_hand_edited_ones(): void
    {
        $program = $this->program();
        $this->complete($this->sessionAt($program, 1), array_fill(0, 3, ['reps' => '5', 'load' => '75 kg', 'done' => true]));

        // A one-off hand edit to week 3.
        Livewire::actingAs($this->coach)->test('pages::coach.workout-builder', ['workout' => $this->sessionAt($program, 3)])
            ->set('items.0.sets', '2')->call('save');

        $row = $program->days()->first()->exercises()->sole();
        Livewire::actingAs($this->coach)->test('pages::coach.program', ['program' => $program])->set("rows.{$row->id}.sets", '5');

        $this->assertSame(3, $this->sessionAt($program, 1)->items->first()->sets, 'Completed history is untouched.');
        $this->assertSame(5, $this->sessionAt($program, 2)->items->first()->sets);
        $this->assertSame(2, $this->sessionAt($program, 3)->items->first()->sets, 'Hand-edited session is left alone.');
        $this->assertSame(5, $this->sessionAt($program, 4)->items->first()->sets);

        // Reset puts it back on the program.
        Livewire::actingAs($this->coach)->test('pages::coach.workout-builder', ['workout' => $this->sessionAt($program, 3)])->call('resetToProgram');
        $this->assertSame(5, $this->sessionAt($program, 3)->items->first()->sets);
        $this->assertNull($this->sessionAt($program, 3)->customized_at);
    }

    public function test_a_new_starting_load_applies_from_the_next_open_week(): void
    {
        $program = $this->program();
        $this->complete($this->sessionAt($program, 1), array_fill(0, 3, ['reps' => '5', 'load' => '75 kg', 'done' => true]));
        $row = $program->days()->first()->exercises()->sole();

        Livewire::actingAs($this->coach)->test('pages::coach.program', ['program' => $program])->set("rows.{$row->id}.load", '90 kg');

        $this->assertSame(2, $row->fresh()->base_week);
        $this->assertSame('75 kg', $this->sessionAt($program, 1)->items->first()->load);
        $this->assertSame('90 kg', $this->sessionAt($program, 2)->items->first()->load);
        $this->assertSame('92.5 kg', $this->sessionAt($program, 3)->items->first()->load);
    }

    public function test_changing_a_weekday_moves_upcoming_sessions_only(): void
    {
        $program = $this->program();
        $this->complete($this->sessionAt($program, 1), array_fill(0, 3, ['reps' => '5', 'load' => '75 kg', 'done' => true]));
        $dayA = $program->days()->first();

        Livewire::actingAs($this->coach)->test('pages::coach.program', ['program' => $program])->set("dayFields.{$dayA->id}.weekday", '2');

        $this->assertSame('2026-10-26', $this->sessionAt($program, 1)->scheduled_on->toDateString());
        $this->assertSame('2026-11-03', $this->sessionAt($program, 2)->scheduled_on->toDateString()); // Tuesday
    }

    public function test_shortening_and_extending_the_program(): void
    {
        $program = $this->program();

        Livewire::actingAs($this->coach)->test('pages::coach.program', ['program' => $program])->set('weeks', 2)->call('saveSettings');
        $this->assertSame(4, $program->workouts()->count());

        Livewire::actingAs($this->coach)->test('pages::coach.program', ['program' => $program->fresh()])->call('extend', 4);
        $this->assertSame(6, $program->fresh()->planned_weeks);
        $this->assertSame(12, $program->workouts()->count());
    }

    public function test_skipping_a_session_sticks_until_restored(): void
    {
        $program = $this->program();
        $week2 = $this->sessionAt($program, 2);

        Livewire::actingAs($this->coach)->test('pages::coach.clients.show', ['client' => $this->client])->call('deleteWorkout', $week2->id);
        app(ProgramScheduler::class)->sync($program);
        $this->assertFalse($program->workouts()->where('week', 2)->where('day', 1)->exists());

        Livewire::actingAs($this->coach)->test('pages::coach.program', ['program' => $program])->call('unskip', $program->days()->first()->id, 2);
        $this->assertTrue($program->workouts()->where('week', 2)->where('day', 1)->exists());
    }

    public function test_removing_a_day_keeps_logged_history(): void
    {
        $program = $this->program();
        $this->complete($this->sessionAt($program, 1), array_fill(0, 3, ['reps' => '5', 'load' => '75 kg', 'done' => true]));
        $dayA = $program->days()->first();

        Livewire::actingAs($this->coach)->test('pages::coach.program', ['program' => $program])->call('removeDay', $dayA->id);

        $this->assertSame(1, $program->workouts()->where('day', 1)->count());
        $this->assertNotNull($program->workouts()->where('day', 1)->sole()->completed_at);
    }

    public function test_rep_ranges_climb_to_the_top_and_stop(): void
    {
        $program = $this->program();
        $row = $program->days()->first()->exercises()->sole();
        Livewire::actingAs($this->coach)->test('pages::coach.program', ['program' => $program])
            ->set("rows.{$row->id}.progression", 'reps')->set("rows.{$row->id}.reps", '8-10');

        $this->assertSame(['8-10', '9-10', '10', '10'], collect(range(1, 4))->map(fn ($w) => $this->sessionAt($program, $w)->items->first()->reps)->all());
    }

    public function test_other_coaches_cannot_edit_a_program(): void
    {
        $program = $this->program();

        $this->actingAs(User::factory()->create())->get(route('coach.programs.show', $program))->assertForbidden();
    }

    public function test_templates_can_repeat_weekly(): void
    {
        $template = Workout::factory()->create(['coach_id' => $this->coach->id, 'client_id' => null, 'title' => 'Conditioning']);

        Livewire::actingAs($this->coach)->test('pages::coach.clients.show', ['client' => $this->client])
            ->set('templateId', (string) $template->id)
            ->set('scheduledOn', '2026-10-27')
            ->set('repeatWeeks', 4)
            ->call('schedule')
            ->assertHasNoErrors();

        $this->assertSame(['2026-10-27', '2026-11-03', '2026-11-10', '2026-11-17'], $this->client->assignedWorkouts()->orderBy('scheduled_on')->pluck('scheduled_on')->map->toDateString()->all());
    }

    public function test_existing_programs_are_converted_to_blueprints(): void
    {
        DB::table('workout_exercises')->delete();
        DB::table('workouts')->delete();
        DB::table('program_exercises')->delete();
        DB::table('program_days')->delete();
        DB::table('programs')->delete();

        $programId = DB::table('programs')->insertGetId(['coach_id' => $this->coach->id, 'client_id' => $this->client->id, 'title' => 'Legacy', 'starts_on' => '2026-10-19', 'planned_weeks' => 6, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[1, '2026-10-19', '2026-10-19 10:00:00', '75 kg'], [2, '2026-10-26', null, '77.5 kg']] as [$week, $date, $done, $load]) {
            $id = DB::table('workouts')->insertGetId(['coach_id' => $this->coach->id, 'client_id' => $this->client->id, 'program_id' => $programId, 'week' => $week, 'day' => 1, 'title' => 'Lower', 'scheduled_on' => $date, 'completed_at' => $done, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('workout_exercises')->insert(['workout_id' => $id, 'exercise_id' => $this->squat->id, 'sets' => 3, 'reps' => '5', 'load' => $load, 'progression' => 'load', 'created_at' => now(), 'updated_at' => now()]);
        }

        $migration = require database_path('migrations/2026_10_29_000002_create_program_blueprints.php');
        (fn () => $this->convertExistingPrograms())->call($migration);

        $day = ProgramDay::sole();
        $this->assertSame(['Lower', 1], [$day->title, $day->weekday]);
        $this->assertSame('75 kg', $day->exercises()->sole()->load);
        $week2 = Workout::where('week', 2)->sole();
        $this->assertNotNull($week2->customized_at, 'Already-planned sessions are frozen so nothing changes under the coach.');
        $this->assertSame($day->exercises()->sole()->id, $week2->items()->sole()->program_exercise_id);
    }
}
