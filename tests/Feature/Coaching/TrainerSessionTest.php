<?php

namespace Tests\Feature\Coaching;

use App\Models\Exercise;
use App\Models\InboxItem;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Services\ProgramScheduler;
use App\Services\TrainerSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TrainerSessionTest extends TestCase
{
    use RefreshDatabase;

    protected User $coach;

    protected User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coach = User::factory()->create(['name' => 'Alex Rivera']);
        $this->client = User::factory()->clientOf($this->coach)->create(['name' => 'Gord Wilson']);
    }

    protected function sessionFor(User $client, $date = null, string $exercise = 'Back Squat'): Workout
    {
        $workout = Workout::factory()->forClient($client, $date ?? today())->create(['title' => 'Lower A']);
        $workout->items()->create(['exercise_id' => Exercise::firstWhere('name', $exercise)?->id ?? Exercise::factory()->create(['name' => $exercise])->id, 'sets' => 2, 'reps' => '5', 'load' => '80 kg', 'rest_seconds' => 120]);

        return $workout->load('items');
    }

    public function test_trainer_runs_todays_session_from_the_client_page(): void
    {
        $workout = $this->sessionFor($this->client);

        Livewire::actingAs($this->coach)->test('pages::coach.clients.show', ['client' => $this->client])
            ->call('runSession')
            ->assertRedirect(route('coach.session', ['w' => $workout->id]));

        $this->actingAs($this->coach)->get(route('coach.session', ['w' => $workout->id]))
            ->assertOk()->assertSee('Session with Gord Wilson')->assertSee('Back Squat');
    }

    public function test_sets_save_as_the_trainer_enters_them_and_finishing_logs_the_session(): void
    {
        $workout = $this->sessionFor($this->client);
        $item = $workout->items->first();

        $page = Livewire::actingAs($this->coach)->test('pages::coach.session', ['w' => (string) $workout->id])
            ->set("logs.{$workout->id}.{$item->id}.0.reps", '5')
            ->set("logs.{$workout->id}.{$item->id}.0.load", '82.5 kg')
            ->set("logs.{$workout->id}.{$item->id}.0.done", true)
            ->assertHasNoErrors();

        // Saved before finishing, so nothing is lost if the phone locks.
        $this->assertSame(['reps' => '5', 'load' => '82.5 kg', 'rpe' => null, 'done' => true], $item->fresh()->logged_sets[0]);
        $this->assertSame($this->coach->id, $workout->fresh()->logged_by_id);
        $this->assertFalse($workout->fresh()->isCompleted());

        $page->set("notes.{$workout->id}", 'Great depth today.')
            ->call('complete', $workout->id)
            ->assertHasNoErrors();

        $workout->refresh();
        $this->assertTrue($workout->isCompleted());
        $this->assertSame('Great depth today.', $workout->session_notes);
    }

    public function test_the_trainer_who_ran_it_gets_no_inbox_items_but_a_covering_teammate_reports_to_the_coach(): void
    {
        $older = $this->sessionFor($this->client, today()->subWeek());
        $older->items->first()->update(['logged_sets' => [['reps' => '5', 'load' => '80 kg', 'done' => true]]]);
        $older->update(['completed_at' => now()->subWeek(), 'reported_at' => now()]);

        // Own trainer: a new best, but no inbox item (they were there).
        $workout = $this->sessionFor($this->client);
        $item = $workout->items->first();
        $item->update(['logged_sets' => [['reps' => '5', 'load' => '85 kg', 'done' => true]]]);
        app(TrainerSession::class)->complete($workout, $this->coach);
        $this->assertSame(0, InboxItem::count());

        // A teammate covering the session: the client's own coach hears about the new best.
        $admin = User::factory()->create();
        $admin->forceFill(['team_id' => $this->coach->team_id, 'team_role' => 'admin'])->save();
        $next = $this->sessionFor($this->client, today()->addDay());
        $next->items->first()->update(['logged_sets' => [['reps' => '5', 'load' => '90 kg', 'done' => true]]]);
        app(TrainerSession::class)->complete($next, $admin);

        $this->assertSame(1, InboxItem::where('coach_id', $this->coach->id)->where('type', 'pr')->count());
    }

    public function test_with_nothing_today_the_next_program_session_moves_to_today_and_stays_there(): void
    {
        $scheduler = app(ProgramScheduler::class);
        $program = $scheduler->create($this->client, 'Strength', today()->startOfWeek(), 4, 1);
        $day = $program->days()->first();
        // Train on a weekday that isn't today, so today has nothing scheduled.
        $day->update(['weekday' => today()->addDays(2)->dayOfWeekIso]);
        $day->exercises()->create(['exercise_id' => Exercise::factory()->create()->id, 'position' => 0, 'sets' => 3, 'reps' => '5', 'load' => '100 kg', 'progression' => 'load']);
        $scheduler->sync($program->fresh());

        $this->assertNull(app(TrainerSession::class)->today($this->client));
        $next = app(TrainerSession::class)->next($this->client);

        Livewire::actingAs($this->coach)->test('pages::coach.clients.show', ['client' => $this->client])
            ->call('runSession')
            ->assertRedirect(route('coach.session', ['w' => $next->id]));

        $this->assertTrue($next->fresh()->scheduled_on->isToday());
        $scheduler->sync($program->fresh());
        $this->assertTrue($next->fresh()->scheduled_on->isToday(), 'A program edit doesn’t move it back.');
    }

    public function test_completing_a_program_session_re_plans_the_next_week(): void
    {
        $scheduler = app(ProgramScheduler::class);
        $program = $scheduler->create($this->client, 'Strength', today()->startOfWeek(), 3, 1);
        $day = $program->days()->first();
        $day->update(['weekday' => today()->dayOfWeekIso]);
        $day->exercises()->create(['exercise_id' => Exercise::factory()->create()->id, 'position' => 0, 'sets' => 1, 'reps' => '5', 'load' => '100 kg', 'progression' => 'load']);
        $scheduler->sync($program->fresh());

        $session = Workout::where('program_id', $program->id)->where('week', 1)->sole()->load('items');
        $item = $session->items->first();

        Livewire::actingAs($this->coach)->test('pages::coach.session', ['w' => (string) $session->id])
            ->set("logs.{$session->id}.{$item->id}.0.reps", '5')
            ->set("logs.{$session->id}.{$item->id}.0.load", '100 kg')
            ->set("logs.{$session->id}.{$item->id}.0.done", true)
            ->call('complete', $session->id);

        $week2 = Workout::where('program_id', $program->id)->where('week', 2)->sole()->items()->first();
        $this->assertNotSame('100 kg', $week2->load, 'Targets hit, so next week steps up.');
    }

    public function test_nothing_scheduled_stays_on_the_page_with_a_message(): void
    {
        Livewire::actingAs($this->coach)->test('pages::coach.clients.show', ['client' => $this->client])
            ->call('runSession')
            ->assertNoRedirect();
    }

    public function test_group_session_from_train_then_add_another_client_and_finish_everyone(): void
    {
        $linda = User::factory()->clientOf($this->coach)->create(['name' => 'Linda Wilson', 'in_person' => true, 'invitation_accepted_at' => null]);
        $sam = User::factory()->clientOf($this->coach)->create(['name' => 'Sam Lee']);
        $gordSession = $this->sessionFor($this->client);
        $lindaSession = $this->sessionFor($linda);
        $samSession = $this->sessionFor($sam, today()->addDays(3));

        $this->actingAs($this->coach)->get(route('coach.train'))->assertOk()
            ->assertSee('Gord Wilson')->assertSee('Linda Wilson')->assertSee('In person')->assertSee('moves to today');

        Livewire::actingAs($this->coach)->test('pages::coach.train')
            ->set('selected', [$this->client->id, $linda->id])
            ->call('start')
            ->assertRedirect(route('coach.session', ['w' => $gordSession->id.','.$lindaSession->id]));

        $page = Livewire::actingAs($this->coach)->test('pages::coach.session', ['w' => $gordSession->id.','.$lindaSession->id])
            ->assertSee('Group session')
            ->call('addClient', $sam->id)
            ->assertSet('w', $gordSession->id.','.$lindaSession->id.','.$samSession->id);

        $this->assertTrue($samSession->fresh()->scheduled_on->isToday());

        $page->call('completeAll');

        foreach ([$gordSession, $lindaSession, $samSession] as $w) {
            $this->assertTrue($w->fresh()->isCompleted());
            $this->assertSame($this->coach->id, $w->fresh()->logged_by_id);
        }
    }

    public function test_trainers_only_run_sessions_for_clients_they_manage(): void
    {
        $other = User::factory()->create();
        $theirs = User::factory()->clientOf($other)->create();
        $theirSession = $this->sessionFor($theirs);
        $item = $theirSession->items->first();

        $this->actingAs($this->coach)->get(route('coach.session', ['w' => $theirSession->id]))->assertRedirect(route('coach.train'));

        $mine = $this->sessionFor($this->client);
        Livewire::actingAs($this->coach)->test('pages::coach.session', ['w' => $mine->id.','.$theirSession->id])
            ->assertDontSee($theirs->name)
            ->set("logs.{$theirSession->id}.{$item->id}.0.reps", '99')
            ->assertStatus(404);
        $this->assertNull($item->fresh()->logged_sets);

        Livewire::actingAs($this->coach)->test('pages::coach.session', ['w' => (string) $mine->id])
            ->call('addClient', $theirs->id)
            ->assertStatus(404);

        // Clients can't open the trainer screens.
        $this->actingAs($this->client)->get(route('coach.train'))->assertForbidden();
    }

    public function test_client_sees_who_logged_the_session_and_the_notes(): void
    {
        $workout = $this->sessionFor($this->client);
        $workout->update(['logged_by_id' => $this->coach->id, 'session_notes' => 'Bar path over mid-foot.', 'completed_at' => now()]);

        $this->actingAs($this->client)->get(route('training.workout', $workout))
            ->assertOk()->assertSee('Logged by Alex in your session')->assertSee('Bar path over mid-foot.');
        $this->actingAs($this->client)->get(route('training.index'))->assertSee('Logged by Alex');
        $this->actingAs($this->coach)->get(route('coach.clients.show', $this->client))->assertSee('Logged by Alex Rivera');
    }

    public function test_in_person_clients_get_no_invite(): void
    {
        Livewire::actingAs($this->coach)->test('pages::coach.clients.index')
            ->set('name', 'Linda Wilson')
            ->set('email', 'linda@wilson.test')
            ->set('inPerson', true)
            ->call('addClient')
            ->assertHasNoErrors();

        $linda = User::firstWhere('email', 'linda@wilson.test');
        $this->assertTrue($linda->isInPersonOnly());

        $this->actingAs($this->coach)->get(route('coach.clients.show', $linda))
            ->assertSee('Linda trains in person')->assertDontSee('Waiting for Linda to join');
        $this->assertFalse(Livewire::actingAs($this->coach)->test('pages::coach.setup')->instance()->pendingClients->contains('id', $linda->id));

        Livewire::actingAs($this->coach)->test('pages::coach.clients.show', ['client' => $linda])->call('setInPerson', false);
        $this->actingAs($this->coach)->get(route('coach.clients.show', $linda))->assertSee('Waiting for Linda to join');
    }

    public function test_last_time_summary_groups_identical_sets(): void
    {
        $service = app(TrainerSession::class);

        $this->assertSame('3 × 5 @ 75 kg, 1 × 3 @ 80 kg', $service->summary([
            ['reps' => '5', 'load' => '75 kg', 'done' => true], ['reps' => '5', 'load' => '75 kg', 'done' => true],
            ['reps' => '5', 'load' => '75 kg', 'done' => true], ['reps' => '3', 'load' => '80 kg', 'done' => true],
            ['reps' => '3', 'load' => '85 kg', 'done' => false],
        ]));
        $this->assertSame('2 × 45s', $service->summary([['reps' => '45s', 'done' => true], ['reps' => '45s', 'done' => true]]));
        $this->assertNull($service->summary([['reps' => null, 'load' => null, 'done' => false]]));

        $workout = $this->sessionFor($this->client, today()->subWeek());
        $workout->items->first()->update(['logged_sets' => [['reps' => '5', 'load' => '80 kg', 'done' => true]]]);
        $workout->update(['completed_at' => now()->subWeek()]);
        $today = $this->sessionFor($this->client);

        $this->assertSame([$today->items->first()->exercise_id => '1 × 5 @ 80 kg'], $service->lastTime($today));
        $this->actingAs($this->coach)->get(route('coach.session', ['w' => $today->id]))->assertSee('Last time: 1 × 5 @ 80 kg');
        $this->assertInstanceOf(WorkoutExercise::class, $today->items->first());
    }
}
