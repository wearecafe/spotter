<?php

namespace Tests\Feature\Coaching;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkoutProgrammingTest extends TestCase
{
    use RefreshDatabase;

    public function test_coach_can_create_a_template_and_add_exercises(): void
    {
        $coach = User::factory()->create();
        $squat = Exercise::factory()->create(['name' => 'Back Squat']);
        $this->actingAs($coach);

        Livewire::test('pages::coach.templates')
            ->set('title', 'Lower A')
            ->call('create');

        $template = $coach->templates()->firstOrFail();
        $this->assertSame('Lower A', $template->title);

        Livewire::test('pages::coach.workout-builder', ['workout' => $template])
            ->call('addExercise', $squat->id)
            ->set('items.0.sets', 5)
            ->set('items.0.reps', '5')
            ->set('items.0.load', '225 lb')
            ->call('save')
            ->assertHasNoErrors();

        $item = $template->items()->firstOrFail();
        $this->assertSame(5, $item->sets);
        $this->assertSame('5 × 5 @ 225 lb', $item->prescription());
    }

    public function test_builder_reorders_and_removes_exercises(): void
    {
        $coach = User::factory()->create();
        [$a, $b] = Exercise::factory()->count(2)->create();
        $template = Workout::factory()->create(['coach_id' => $coach->id]);

        $component = Livewire::actingAs($coach)->test('pages::coach.workout-builder', ['workout' => $template])
            ->call('addExercise', $a->id)
            ->call('addExercise', $b->id)
            ->call('move', 1, -1);

        $this->assertSame([$b->id, $a->id], $template->items()->pluck('exercise_id')->all());

        $component->call('removeItem', 0);
        $this->assertSame([$a->id], $template->items()->pluck('exercise_id')->all());
    }

    public function test_coach_cannot_add_another_coaches_custom_exercise(): void
    {
        $coach = User::factory()->create();
        $private = Exercise::factory()->create(['coach_id' => User::factory()->create()->id]);
        $template = Workout::factory()->create(['coach_id' => $coach->id]);

        Livewire::actingAs($coach)->test('pages::coach.workout-builder', ['workout' => $template])
            ->call('addExercise', $private->id)
            ->assertNotFound();

        $this->assertSame(0, $template->items()->count());
    }

    public function test_scheduling_a_template_copies_it_onto_the_clients_calendar(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $template = Workout::factory()->create(['coach_id' => $coach->id, 'title' => 'Upper A']);
        $template->items()->create(['exercise_id' => Exercise::factory()->create()->id, 'sets' => 4, 'reps' => '8']);

        Livewire::actingAs($coach)->test('pages::coach.clients.show', ['client' => $client])
            ->set('templateId', (string) $template->id)
            ->set('scheduledOn', today()->addDay()->toDateString())
            ->call('schedule')
            ->assertHasNoErrors();

        $assigned = $client->assignedWorkouts()->firstOrFail();
        $this->assertSame('Upper A', $assigned->title);
        $this->assertSame($template->id, $assigned->template_id);
        $this->assertTrue($assigned->scheduled_on->isSameDay(today()->addDay()));
        $this->assertSame(4, $assigned->items()->first()->sets);
        $this->assertSame(1, $template->items()->count(), 'Template keeps its own exercises');
        $this->assertNotSame($template->items()->first()->id, $assigned->items()->first()->id);

        // Regression: the copy must not inherit the template's already-loaded items relation.
        $template->load('items');
        $copy = $template->assignTo($client, today());
        $this->assertSame($copy->id, $copy->items->first()->workout_id);
    }

    public function test_coach_cannot_schedule_another_coaches_template(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $foreign = Workout::factory()->create();

        Livewire::actingAs($coach)->test('pages::coach.clients.show', ['client' => $client])
            ->set('templateId', (string) $foreign->id)
            ->call('schedule')
            ->assertHasErrors('templateId');
    }

    public function test_coach_cannot_edit_another_coaches_workout(): void
    {
        $workout = Workout::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('coach.workouts.edit', $workout))
            ->assertForbidden();
    }

    public function test_coach_can_add_custom_exercises_but_not_edit_shared_ones(): void
    {
        $coach = User::factory()->create();
        $shared = Exercise::factory()->create();

        Livewire::actingAs($coach)->test('pages::coach.exercises')
            ->set('name', 'Landmine Press')
            ->set('muscle_group', 'Shoulders')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($coach->exercises()->where('name', 'Landmine Press')->exists());

        Livewire::actingAs($coach)->test('pages::coach.exercises')
            ->call('edit', $shared->id)
            ->assertForbidden();
    }
}
