<?php

namespace Tests\Feature\Coaching;

use App\Models\Exercise;
use App\Models\Program;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Services\Progression;
use App\Support\Load;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProgramProgressionTest extends TestCase
{
    use RefreshDatabase;

    protected User $coach;

    protected User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coach = User::factory()->create();
        $this->client = User::factory()->clientOf($this->coach)->create(['name' => 'Maya Chen']);
    }

    protected function program(): Program
    {
        Livewire::actingAs($this->coach)->test('pages::coach.clients.show', ['client' => $this->client])
            ->set('programTitle', 'Strength block')
            ->set('programStart', '2026-11-02')
            ->set('programDays', 2)
            ->call('createProgram')
            ->assertHasNoErrors();

        return Program::sole();
    }

    protected function row(Program $program, string $name, array $attributes): WorkoutExercise
    {
        $day = $program->workouts()->where('week', 1)->first();

        return $day->items()->create(['exercise_id' => Exercise::factory()->create(['name' => $name])->id] + $attributes);
    }

    public function test_load_maths(): void
    {
        $this->assertSame('77.5 kg', Load::add('75 kg', '2.5 kg'));
        $this->assertSame('140 lb', Load::add('135 lb', '5'));
        $this->assertSame('2 × 18 kg', Load::add('2 × 16 kg', '2'));
        $this->assertSame('80 kg', Load::add('75 kg', '5%'));
        $this->assertSame('BW', Load::add('BW', '2.5 kg'));
        $this->assertEqualsWithDelta(61.23, Load::toKg('135 lb'), 0.01);
        $this->assertSame(8.0, Load::high('7-8'));
    }

    public function test_coach_creates_a_program_with_a_first_week_of_days(): void
    {
        $program = $this->program();

        $this->assertSame(['Day A', 'Day B'], $program->workouts()->pluck('title')->all());
        $this->assertSame(1, $program->lastWeek());
        $this->actingAs($this->coach)->get(route('coach.programs.show', $program))->assertOk()->assertSee('Strength block');
    }

    public function test_next_week_applies_rules_only_where_the_client_earned_it(): void
    {
        $program = $this->program();

        $squat = $this->row($program, 'Back Squat', ['sets' => 2, 'reps' => '5', 'load' => '75 kg', 'target_rpe' => '8', 'progression' => 'load',
            'logged_sets' => [['reps' => '5', 'load' => '75 kg', 'rpe' => '7.5', 'done' => true], ['reps' => '5', 'load' => '75 kg', 'rpe' => '8', 'done' => true]]]);
        $rdl = $this->row($program, 'Romanian Deadlift', ['sets' => 2, 'reps' => '8', 'load' => '60 kg', 'target_rpe' => '8', 'progression' => 'load',
            'logged_sets' => [['reps' => '8', 'load' => '60 kg', 'rpe' => '9', 'done' => true], ['reps' => '7', 'load' => '60 kg', 'rpe' => '9.5', 'done' => true]]]);
        $row = $this->row($program, 'Seated Cable Row', ['sets' => 3, 'reps' => '8-10', 'progression' => 'reps',
            'logged_sets' => array_fill(0, 3, ['reps' => '10', 'load' => '50 kg', 'done' => true])]);
        $plank = $this->row($program, 'Plank', ['sets' => 3, 'reps' => '30s', 'progression' => 'none']);

        Livewire::actingAs($this->coach)->test('pages::coach.program', ['program' => $program])
            ->call('generateNextWeek')
            ->assertSet('week', 2)
            ->assertSee('cell-changed', false);

        $week2 = fn (WorkoutExercise $original) => WorkoutExercise::query()
            ->where('exercise_id', $original->exercise_id)
            ->whereHas('workout', fn ($q) => $q->where('week', 2))
            ->sole();

        $this->assertSame('77.5 kg', $week2($squat)->load);
        $this->assertSame(['load'], $week2($squat)->changed_fields);
        $this->assertSame('60 kg', $week2($rdl)->load, 'Missed reps and high RPE should hold the load.');
        $this->assertStringContainsString('Held', $week2($rdl)->change_reason);
        $this->assertSame('9-10', $week2($row)->reps);
        $this->assertSame('30s', $week2($plank)->reps);
        $this->assertNull($week2($squat)->logged_sets, 'A new week starts unlogged.');
        $this->assertSame(2, $program->workouts()->where('week', 2)->count());
        $this->assertTrue($program->workouts()->where('week', 2)->first()->scheduled_on->isSameDay($program->starts_on->addWeek()));
    }

    public function test_cells_save_as_the_coach_edits_them(): void
    {
        $program = $this->program();
        $squat = $this->row($program, 'Back Squat', ['sets' => 3, 'reps' => '5', 'load' => '75 kg']);

        Livewire::actingAs($this->coach)->test('pages::coach.program', ['program' => $program])
            ->set("rows.{$squat->id}.load", '80 kg')
            ->set("rows.{$squat->id}.progression", 'load')
            ->set("rows.{$squat->id}.sets", '0')
            ->assertHasErrors("rows.{$squat->id}.sets");

        $squat->refresh();
        $this->assertSame('80 kg', $squat->load);
        $this->assertSame('load', $squat->progression);
        $this->assertSame(3, $squat->sets);
    }

    public function test_unlogged_rows_hold(): void
    {
        $item = new WorkoutExercise(['sets' => 3, 'reps' => '5', 'load' => '75 kg', 'progression' => 'load']);

        $this->assertSame('75 kg', app(Progression::class)->nextPrescription($item)['load']);
    }

    public function test_other_coaches_cannot_open_a_program(): void
    {
        $program = $this->program();

        $this->actingAs(User::factory()->create())->get(route('coach.programs.show', $program))->assertForbidden();
    }
}
