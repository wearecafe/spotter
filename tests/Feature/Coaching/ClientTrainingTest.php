<?php

namespace Tests\Feature\Coaching;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientTrainingTest extends TestCase
{
    use RefreshDatabase;

    protected function workoutFor(User $client): Workout
    {
        $workout = Workout::factory()->forClient($client)->create(['title' => 'Push Day']);
        $workout->items()->create(['exercise_id' => Exercise::factory()->create(['name' => 'Bench Press'])->id, 'sets' => 2, 'reps' => '8']);

        return $workout;
    }

    public function test_client_sees_todays_workout(): void
    {
        $client = User::factory()->clientOf(User::factory()->create())->create();
        $this->workoutFor($client);

        $this->actingAs($client)->get(route('training.index'))->assertSee('Push Day');
    }

    public function test_client_can_log_sets_and_complete_a_workout(): void
    {
        $client = User::factory()->clientOf(User::factory()->create())->create();
        $workout = $this->workoutFor($client);
        $item = $workout->items()->first();

        $this->actingAs($client)->get(route('training.workout', $workout))->assertOk()->assertSee('Bench Press');

        Livewire::actingAs($client)->test('pages::training.workout', ['workout' => $workout])
            ->set("logs.{$item->id}.0.reps", '8')
            ->set("logs.{$item->id}.0.load", '135 lb')
            ->set("logs.{$item->id}.0.done", true)
            ->set("logs.{$item->id}.1.reps", '7')
            ->set('feedback', 'Last set was a grind')
            ->call('complete')
            ->assertHasNoErrors();

        $workout->refresh();
        $this->assertTrue($workout->isCompleted());
        $this->assertSame('Last set was a grind', $workout->client_feedback);
        $this->assertSame([
            ['reps' => '8', 'load' => '135 lb', 'done' => true],
            ['reps' => '7', 'load' => null, 'done' => false],
        ], $item->refresh()->logged_sets);
    }

    public function test_coach_sees_logged_results_in_the_builder(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $workout = $this->workoutFor($client);
        $workout->items()->first()->update(['logged_sets' => [['reps' => '8', 'load' => '135 lb', 'done' => true]]]);

        $this->actingAs($coach)->get(route('coach.workouts.edit', $workout))
            ->assertOk()
            ->assertSee('Client logged')
            ->assertSee('135 lb');
    }

    public function test_client_cannot_open_someone_elses_workout(): void
    {
        $coach = User::factory()->create();
        $workout = $this->workoutFor(User::factory()->clientOf($coach)->create());
        $other = User::factory()->clientOf($coach)->create();

        $this->actingAs($other)->get(route('training.workout', $workout))->assertForbidden();
    }

    public function test_invited_clients_without_a_password_cannot_log_in(): void
    {
        $client = User::factory()->clientOf(User::factory()->create())->invited()->create();

        $this->post(route('login.store'), ['email' => $client->email, 'password' => ''])
            ->assertSessionHasErrors();

        $this->assertGuest();
    }
}
