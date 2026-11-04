<?php

namespace Tests\Feature\Coaching;

use App\Models\Exercise;
use App\Models\ProgramExercise;
use App\Models\User;
use App\Models\Workout;
use App\Services\ProgramScheduler;
use App\Support\Restrictions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RestrictionsAndSwapTest extends TestCase
{
    use RefreshDatabase;

    public function test_matching_catches_common_lifts_and_leaves_alone_lookalikes(): void
    {
        $e = fn (string $name, ?string $category = null) => new Exercise(['name' => $name, 'category' => $category]);

        $this->assertTrue(Restrictions::conflicts($e('Barbell Back Squat'), 'knee_flexion'));
        $this->assertTrue(Restrictions::conflicts($e('Bulgarian Split Squat'), 'knee_flexion'));
        $this->assertFalse(Restrictions::conflicts($e('Romanian Deadlift'), 'knee_flexion'));
        $this->assertTrue(Restrictions::conflicts($e('Romanian Deadlift'), 'spinal_loading'));
        $this->assertTrue(Restrictions::conflicts($e('Seated Dumbbell Shoulder Press'), 'overhead'));
        $this->assertTrue(Restrictions::conflicts($e('Lateral Bound', 'plyometrics'), 'impact'));
        $this->assertTrue(Restrictions::conflicts($e('Hammer Curl'), 'elbow'));
        $this->assertFalse(Restrictions::conflicts($e('Lying Leg Curl'), 'elbow'));
        $this->assertFalse(Restrictions::conflicts($e('Glute Bridge'), 'neck'));
        $this->assertFalse(Restrictions::conflicts($e('Front Barbell Squat'), 'neck'));
    }

    public function test_coach_records_restrictions_and_sees_conflicts_in_the_builder(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create(['name' => 'Jordan Lee']);

        Livewire::actingAs($coach)->test('pages::coach.clients.show', ['client' => $client])
            ->set('restrictionKey', 'knee_flexion')
            ->set('restrictionSide', 'left')
            ->set('restrictionNote', 'Meniscus, cleared for partial range')
            ->call('addRestriction')
            ->assertHasNoErrors();

        $this->assertSame([['key' => 'knee_flexion', 'side' => 'left', 'note' => 'Meniscus, cleared for partial range']], $client->fresh()->restrictions);
        $this->assertSame('Knee (left): No deep knee bends', Restrictions::describe(Restrictions::forClient($client->fresh())->first()));

        $workout = Workout::factory()->forClient($client)->create();
        $workout->items()->create(['exercise_id' => Exercise::factory()->create(['name' => 'Goblet Squat'])->id, 'sets' => 3, 'reps' => '10']);
        $workout->items()->create(['exercise_id' => Exercise::factory()->create(['name' => 'Seated Row'])->id, 'sets' => 3, 'reps' => '10']);

        $this->actingAs($coach)->get(route('coach.workouts.edit', $workout))
            ->assertOk()
            ->assertSee('Meniscus, cleared for partial range')
            ->assertSeeInOrder(['Goblet Squat', 'Knee (left): No deep knee bends', 'Seated Row']);

        Livewire::actingAs($coach)->test('pages::coach.clients.show', ['client' => $client])->call('removeRestriction', 'knee_flexion');
        $this->assertNull($client->fresh()->restrictions);
    }

    public function test_unknown_restriction_is_rejected(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();

        Livewire::actingAs($coach)->test('pages::coach.clients.show', ['client' => $client])
            ->set('restrictionKey', 'telepathy')
            ->call('addRestriction')
            ->assertHasErrors('restrictionKey');
    }

    public function test_swapping_an_exercise_replaces_it_on_every_day_of_the_program(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 2)); // Monday

        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create(['restrictions' => [['key' => 'knee_flexion', 'side' => 'both', 'note' => null]]]);
        $scheduler = app(ProgramScheduler::class);
        $program = $scheduler->create($client, 'Strength', '2026-11-02', 3, 2); // Mon + Thu
        $squat = Exercise::factory()->create(['name' => 'Back Squat']);
        $boxSquat = Exercise::factory()->create(['name' => 'Hip Thrust']);
        foreach ($program->days as $day) {
            $day->exercises()->create(['exercise_id' => $squat->id, 'sets' => 4, 'reps' => '6', 'load' => '80 kg', 'progression' => 'load']);
        }
        $scheduler->sync($program);

        // Week 1 Monday is done, week 2 Monday was edited by hand: both keep the squat.
        $done = Workout::where('program_id', $program->id)->where('week', 1)->where('day', 1)->sole();
        $done->update(['completed_at' => now()]);
        $edited = Workout::where('program_id', $program->id)->where('week', 2)->where('day', 1)->sole();
        $scheduler->customize($edited);

        $this->actingAs($coach)->get(route('coach.programs.show', $program))->assertOk()->assertSee('Knee: No deep knee bends');

        $row = ProgramExercise::query()->whereHas('day', fn ($q) => $q->where('program_id', $program->id))->first();

        Livewire::actingAs($coach)->test('pages::coach.program', ['program' => $program])
            ->call('openSwap', $row->id)
            ->call('pick', $boxSquat->id);

        $this->assertSame(0, ProgramExercise::where('exercise_id', $squat->id)->count());
        $this->assertSame(2, ProgramExercise::where('exercise_id', $boxSquat->id)->where('sets', 4)->where('reps', '6')->count());

        $this->assertSame($squat->id, $done->items()->sole()->exercise_id);
        $this->assertSame($squat->id, $edited->items()->sole()->exercise_id);
        $following = Workout::where('program_id', $program->id)->whereNull('customized_at')->whereNull('completed_at')->get();
        $this->assertNotEmpty($following);
        $following->each(fn (Workout $w) => $this->assertSame($boxSquat->id, $w->items()->sole()->exercise_id));
    }

    public function test_swap_reports_hand_edited_sessions_left_alone(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 2));

        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $scheduler = app(ProgramScheduler::class);
        $program = $scheduler->create($client, 'Strength', '2026-11-02', 2, 1);
        $press = Exercise::factory()->create();
        $program->days()->sole()->exercises()->create(['exercise_id' => $press->id, 'sets' => 3, 'reps' => '8']);
        $scheduler->sync($program);
        $scheduler->customize(Workout::where('program_id', $program->id)->where('week', 2)->sole());

        $this->assertSame(1, $scheduler->swapExercise($program, $press->id, Exercise::factory()->create()->id));
    }
}
