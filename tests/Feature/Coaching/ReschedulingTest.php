<?php

namespace Tests\Feature\Coaching;

use App\Models\Exercise;
use App\Models\InboxItem;
use App\Models\User;
use App\Models\Workout;
use App\Services\ProgramScheduler;
use App\Services\Rescheduling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReschedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(9, 0)); // Tuesday
    }

    public function test_client_moves_a_session_to_another_day_that_week_and_the_coach_is_told(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $workout = Workout::factory()->forClient($client, '2026-11-04')->create(['title' => 'Upper A']); // Wednesday

        $options = app(Rescheduling::class)->options($workout)->map->toDateString()->all();
        $this->assertSame(['2026-11-03', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'], $options);

        Livewire::actingAs($client)->test('pages::training.workout', ['workout' => $workout])
            ->set('moveTo', '2026-11-06')
            ->call('move')
            ->assertHasNoErrors();

        $workout->refresh();
        $this->assertSame('2026-11-06', $workout->scheduled_on->toDateString());
        $this->assertSame('2026-11-04', $workout->moved_from->toDateString());

        $item = InboxItem::where('type', 'moved')->sole();
        $this->assertSame($coach->id, $item->coach_id);
        $this->assertSame('Moved Upper A from Wed 4 Nov to Fri 6 Nov', $item->headline);
    }

    public function test_session_cannot_leave_its_week_or_go_into_the_past(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $workout = Workout::factory()->forClient($client, '2026-11-04')->create();

        foreach (['2026-11-02', '2026-11-09', '2026-11-04'] as $day) {
            Livewire::actingAs($client)->test('pages::training.workout', ['workout' => $workout])
                ->set('moveTo', $day)
                ->call('move')
                ->assertHasErrors('moveTo');
        }

        // Moving it again stays anchored to the week it was planned in.
        app(Rescheduling::class)->move($client, $workout, '2026-11-08');
        $this->assertFalse(app(Rescheduling::class)->options($workout->fresh())->contains(fn ($d) => $d->toDateString() === '2026-11-09'));

        // Back to its planned day, it's no longer marked as moved.
        app(Rescheduling::class)->move($client, $workout->fresh(), '2026-11-04');
        $this->assertNull($workout->fresh()->moved_from);
    }

    public function test_a_missed_session_earlier_in_the_week_can_still_be_done_later_that_week(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $missed = Workout::factory()->forClient($client, '2026-11-02')->create(); // Monday, not done

        app(Rescheduling::class)->move($client, $missed, '2026-11-05');

        $this->assertSame('2026-11-05', $missed->fresh()->scheduled_on->toDateString());
    }

    public function test_coach_can_turn_off_moving_for_a_client(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $workout = Workout::factory()->forClient($client, '2026-11-04')->create();

        Livewire::actingAs($coach)->test('pages::coach.clients.show', ['client' => $client])->set('canReschedule', false);
        $this->assertFalse($client->fresh()->can_reschedule);

        Livewire::actingAs($client->fresh())->test('pages::training.workout', ['workout' => $workout])
            ->assertDontSee(__('Move to another day'))
            ->set('moveTo', '2026-11-05')
            ->call('move')
            ->assertHasErrors('moveTo');

        $this->assertSame('2026-11-04', $workout->fresh()->scheduled_on->toDateString());
    }

    public function test_started_sessions_cannot_be_moved(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $workout = Workout::factory()->forClient($client, '2026-11-04')->create();
        $workout->items()->create(['exercise_id' => Exercise::factory()->create()->id, 'logged_sets' => [['reps' => '5', 'load' => '60 kg', 'done' => true]]]);

        $this->assertFalse(app(Rescheduling::class)->canMove($client, $workout->fresh()));
    }

    public function test_a_moved_program_session_keeps_its_new_day_and_still_follows_program_edits(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $scheduler = app(ProgramScheduler::class);
        $program = $scheduler->create($client, 'Strength', '2026-11-02', 2, 1); // Mondays
        $day = $program->days()->sole();
        $squat = Exercise::factory()->create();
        $day->exercises()->create(['exercise_id' => $squat->id, 'sets' => 3, 'reps' => '5', 'load' => '60 kg']);
        $scheduler->sync($program);

        $week2 = Workout::where('program_id', $program->id)->where('week', 2)->sole(); // Mon 9 Nov
        $this->travelTo(now()->setDate(2026, 11, 9));
        app(Rescheduling::class)->move($client, $week2, '2026-11-11');

        // The coach changes the program: the moved session takes the change but stays on Wednesday.
        $day->exercises()->sole()->update(['sets' => 4]);
        $scheduler->sync($program->fresh());

        $week2->refresh();
        $this->assertSame('2026-11-11', $week2->scheduled_on->toDateString());
        $this->assertSame(4, $week2->items()->sole()->sets);
    }

    public function test_archived_client_cannot_move_sessions(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->archived()->create();
        $workout = Workout::factory()->forClient($client, '2026-11-04')->create();

        $this->assertFalse(app(Rescheduling::class)->canMove($client, $workout));
    }
}
