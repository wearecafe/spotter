<?php

namespace Tests\Feature\Coaching;

use App\Models\Habit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HabitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_coach_adds_a_number_habit_and_client_logs_it(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();

        Livewire::actingAs($coach)->test('pages::habits.board', ['client' => $client])
            ->set('name', 'Steps')
            ->set('type', 'number')
            ->set('target', '8000')
            ->set('unit', 'steps')
            ->call('addHabit')
            ->assertHasNoErrors();

        $habit = Habit::sole();
        $this->assertSame($coach->id, $habit->created_by_id);
        $this->assertFalse($habit->isClientOwn());
        $this->assertSame('Daily · target 8,000 steps', $habit->describe());

        $board = Livewire::actingAs($client)->test('pages::habits.board', ['client' => $client])
            ->set("values.{$habit->id}", '6500')
            ->call('saveValue', $habit->id);

        $log = $habit->logs()->sole();
        $this->assertEquals(6500, $log->value);
        $this->assertFalse($log->done);

        $board->set("values.{$habit->id}", '9100')->call('saveValue', $habit->id);
        $this->assertTrue($habit->logs()->sole()->done);
        $this->assertSame(['done' => 1, 'goal' => 7, 'met' => false], $habit->weekProgress());
    }

    public function test_client_ticks_off_a_weekly_habit_today_and_yesterday(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 4)); // a Wednesday

        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $walk = $client->habits()->create(['created_by_id' => $coach->id, 'name' => '30-minute walk', 'days_per_week' => 3]);

        $board = Livewire::actingAs($client)->test('pages::habits.board', ['client' => $client])
            ->call('toggle', $walk->id)
            ->set('day', 'yesterday')
            ->call('toggle', $walk->id);

        $this->assertSame(['2026-11-03', '2026-11-04'], $walk->logs()->orderBy('logged_on')->get()->map(fn ($l) => $l->logged_on->toDateString())->all());
        $this->assertSame(['done' => 2, 'goal' => 3, 'met' => false], $walk->weekProgress());

        // Toggling again un-ticks it.
        $board->call('toggle', $walk->id);
        $this->assertSame(1, $walk->logs()->where('done', true)->count());
    }

    public function test_client_can_add_and_stop_their_own_habits_but_not_their_coaches(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $coachHabit = $client->habits()->create(['created_by_id' => $coach->id, 'name' => 'Water']);

        $board = Livewire::actingAs($client)->test('pages::habits.board', ['client' => $client])
            ->set('name', 'Stretch 10 minutes')
            ->call('addHabit')
            ->assertHasNoErrors();

        $own = Habit::where('name', 'Stretch 10 minutes')->sole();
        $this->assertTrue($own->isClientOwn());

        $board->call('endHabit', $own->id);
        $this->assertNotNull($own->fresh()->ended_at);
        $this->assertModelExists($own); // ended, not deleted: its history is kept

        $board->call('endHabit', $coachHabit->id)->assertForbidden();
        $this->assertNull($coachHabit->fresh()->ended_at);
    }

    public function test_coach_sees_their_clients_habits_and_cannot_log_for_them(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $habit = $client->habits()->create(['created_by_id' => $client->id, 'name' => 'Sleep by 11']);
        $habit->log(today(), done: true);

        $this->actingAs($coach)->get(route('coach.clients.show', $client))
            ->assertOk()
            ->assertSee('Sleep by 11')
            ->assertSee(__('Their own'));

        Livewire::actingAs($coach)->test('pages::habits.board', ['client' => $client])->call('toggle', $habit->id)->assertForbidden();
    }

    public function test_another_coach_cannot_see_or_change_a_clients_habits(): void
    {
        $client = User::factory()->clientOf(User::factory()->create())->create();

        Livewire::actingAs(User::factory()->create())->test('pages::habits.board', ['client' => $client])->assertForbidden();
        Livewire::actingAs(User::factory()->clientOf(User::factory()->create())->create())->test('pages::habits.board', ['client' => $client])->assertForbidden();
    }

    public function test_number_habit_needs_a_target(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();

        Livewire::actingAs($coach)->test('pages::habits.board', ['client' => $client])
            ->set('name', 'Water')
            ->set('type', 'number')
            ->call('addHabit')
            ->assertHasErrors('target');
    }

    public function test_read_only_archived_client_cannot_log_habits(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->archived()->create();
        $habit = $client->habits()->create(['created_by_id' => $coach->id, 'name' => 'Water']);

        Livewire::actingAs($client)->test('pages::habits.board', ['client' => $client])->call('toggle', $habit->id);

        $this->assertSame(0, $habit->logs()->count());
    }

    public function test_client_home_shows_habits(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $client->habits()->create(['created_by_id' => $coach->id, 'name' => 'Protein at breakfast']);

        $this->actingAs($client)->get(route('training.index'))->assertOk()->assertSee('Protein at breakfast');
    }
}
