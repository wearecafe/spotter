<?php

namespace Tests\Feature\Coaching;

use App\Models\Exercise;
use App\Models\InboxItem;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExerciseNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function workoutFor(User $client): Workout
    {
        $workout = Workout::factory()->forClient($client)->create(['title' => 'Lower A']);
        $workout->items()->create(['exercise_id' => Exercise::factory()->create(['name' => 'Split squat'])->id, 'sets' => 2, 'reps' => '8', 'load' => '20 kg']);

        return $workout;
    }

    public function test_client_saves_a_note_on_an_exercise(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $workout = $this->workoutFor($client);
        $item = $workout->items->first();

        Livewire::actingAs($client)->test('pages::training.workout', ['workout' => $workout])
            ->set("notes.{$item->id}.note", 'Eased off, grip was going')
            ->call('saveProgress')
            ->assertHasNoErrors();

        $this->assertSame('Eased off, grip was going', $item->fresh()->client_note);
        $this->assertFalse($item->fresh()->pain);
        $this->assertSame(0, InboxItem::where('type', 'pain')->count());
    }

    public function test_flagging_pain_alerts_the_coach_right_away_and_only_once(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create(['name' => 'Sam Rivera']);
        $workout = $this->workoutFor($client);
        $item = $workout->items->first();

        $page = Livewire::actingAs($client)->test('pages::training.workout', ['workout' => $workout])
            ->set("notes.{$item->id}.note", 'Knee was hurting on the way down')
            ->set("notes.{$item->id}.pain", true)
            ->call('saveProgress', false);

        $alert = InboxItem::where('type', 'pain')->sole();
        $this->assertSame($coach->id, $alert->coach_id);
        $this->assertSame('Pain on Split squat', $alert->headline);
        $this->assertSame(90, $alert->priority);
        $this->assertTrue($item->fresh()->pain);
        // Rule-based draft asks about it rather than diagnosing.
        $this->assertStringContainsString('Don’t push through pain', (string) $alert->fresh()->draft_reply);

        // Unflag, flag again, complete: still one alert.
        $page->set("notes.{$item->id}.pain", false)->call('saveProgress', false)
            ->set("notes.{$item->id}.pain", true)->call('saveProgress', false)
            ->call('complete');

        $this->assertSame(1, InboxItem::where('type', 'pain')->count());

        $this->actingAs($coach)->get(route('coach.inbox'))->assertOk()->assertSee('Knee was hurting on the way down');
    }

    public function test_coach_sees_notes_and_pain_in_the_workout(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $workout = $this->workoutFor($client);
        $workout->items->first()->update(['client_note' => 'Left hip felt tight', 'pain' => true]);

        $this->actingAs($coach)->get(route('coach.workouts.edit', $workout))
            ->assertOk()
            ->assertSee('Left hip felt tight')
            ->assertSee(__('Pain flagged'));
    }

    public function test_notes_are_not_copied_when_a_workout_becomes_a_template(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $workout = $this->workoutFor($client);
        $workout->items->first()->update(['client_note' => 'Private to this session', 'pain' => true]);

        Livewire::actingAs($coach)->test('pages::coach.workout-builder', ['workout' => $workout])->call('saveAsTemplate');

        $template = Workout::query()->templates()->sole();
        $this->assertNull($template->items->first()->client_note);
        $this->assertFalse($template->items->first()->pain);
    }
}
