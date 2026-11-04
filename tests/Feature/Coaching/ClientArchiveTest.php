<?php

namespace Tests\Feature\Coaching;

use App\Models\CheckIn;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Models\Workout;
use App\Services\ClientArchive;
use App\Services\InboxFeed;
use App\Services\TenantStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class ClientArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_archiving_keeps_every_record_and_unarchiving_restores_the_client(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create(['name' => 'Maya Chen']);
        $workout = Workout::factory()->forClient($client, today()->subDay())->create(['completed_at' => now()]);
        Message::create(['coach_id' => $coach->id, 'client_id' => $client->id, 'sender_id' => $client->id, 'body' => 'See you Monday']);
        CheckIn::create(['client_id' => $client->id, 'week_of' => today()->startOfWeek()->toDateString(), 'energy' => 4]);

        Livewire::actingAs($coach)->test('pages::coach.clients.show', ['client' => $client])
            ->set('archiveAccess', 'locked')
            ->call('archive')
            ->assertHasNoErrors();

        $client->refresh();
        $this->assertTrue($client->isArchived());
        $this->assertSame('locked', $client->archived_access);
        $this->assertSame('coach', $client->archived_reason);
        $this->assertModelExists($workout);
        $this->assertSame(1, Message::where('client_id', $client->id)->count());
        $this->assertSame(1, CheckIn::where('client_id', $client->id)->count());

        Livewire::actingAs($coach)->test('pages::coach.clients.show', ['client' => $client])->call('unarchive');

        $client->refresh();
        $this->assertFalse($client->isArchived());
        $this->assertNull($client->archived_access);
    }

    public function test_archived_clients_leave_the_client_list_but_show_under_archived(): void
    {
        $coach = User::factory()->create();
        User::factory()->clientOf($coach)->create(['name' => 'Active Annie']);
        User::factory()->clientOf($coach)->archived()->create(['name' => 'Archived Arlo']);

        Livewire::actingAs($coach)->test('pages::coach.clients.index')
            ->assertSee('Active Annie')
            ->assertDontSee('Archived Arlo')
            ->set('status', 'archived')
            ->assertSee('Archived Arlo')
            ->assertDontSee('Active Annie');

        $this->assertSame(['Active Annie'], $coach->visibleClients()->pluck('name')->all());
        $this->assertCount(2, $coach->visibleClients(withArchived: true)->get());
    }

    public function test_archived_clients_inbox_items_wait_and_no_new_ones_are_raised(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        InboxItem::create(['coach_id' => $coach->id, 'client_id' => $client->id, 'type' => 'message', 'headline' => 'Hi']);

        $this->assertSame(1, $coach->inboxItems()->actionable()->count());

        app(ClientArchive::class)->archive($client);
        $this->assertSame(0, $coach->inboxItems()->actionable()->count());

        // Missed sessions while archived are not flagged.
        Workout::factory()->forClient($client, today()->subDays(2))->create();
        Workout::factory()->forClient($client, today()->subDays(3))->create();
        $this->assertSame(0, app(InboxFeed::class)->scanMissedSessions($coach));

        app(ClientArchive::class)->unarchive($client);
        $this->assertSame(1, $coach->inboxItems()->actionable()->count());
    }

    public function test_archived_clients_count_toward_the_bill_only_for_the_month_they_were_archived(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 15));

        $coach = User::factory()->create();
        $team = $coach->team;
        $team->update(['plan' => 'coach']);

        User::factory()->count(16)->clientOf($coach)->create();
        User::factory()->clientOf($coach)->archived(at: now()->subDays(3))->create(); // archived this month: still billed
        User::factory()->count(5)->clientOf($coach)->archived(at: now()->subMonth())->create(); // archived last month: not billed

        $stats = app(TenantStats::class);
        $team = $stats->teamsQuery()->find($team->id);

        $this->assertSame(22, $team->clients_count);
        $this->assertSame(17, $team->billable_clients_count);
        $this->assertSame(17, $stats->forTeam($team)['billable_clients']);
        // 15 included, 2 extra at $1.50.
        $this->assertSame(39 + 2 * 1.5, $stats->monthlyEstimate($team, $team->billable_clients_count));

        // Next month the client archived on the 12th drops off too.
        $this->travelTo(now()->setDate(2026, 12, 1));
        $this->assertSame(16, User::where('team_id', $team->id)->billable()->count());
    }

    public function test_locked_out_archived_client_is_signed_out(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->archived('locked')->create();

        $this->actingAs($client)->get(route('training.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertGuest();
    }

    public function test_read_only_archived_client_can_look_back_but_not_log_anything(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->archived('read_only')->create();
        $workout = Workout::factory()->forClient($client)->create();

        $this->actingAs($client)->get(route('training.index'))->assertOk()->assertSee(__('Your coaching is archived.'));
        $this->actingAs($client)->get(route('training.workout', $workout))->assertOk();

        Livewire::actingAs($client)->test('pages::training.workout', ['workout' => $workout])->call('complete');
        $this->assertNull($workout->fresh()->completed_at);

        Livewire::actingAs($client)->test('pages::training.check-in')->set('energy', 4)->call('submit');
        $this->assertSame(0, CheckIn::where('client_id', $client->id)->count());
    }

    public function test_archived_conversation_is_read_only_for_the_coach(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->archived()->create();

        Livewire::actingAs($coach)->test('pages::chat.thread', ['client' => $client])
            ->assertSee(__(':name is archived, so this conversation is read-only. Unarchive them to message again.', ['name' => str($client->name)->before(' ')]))
            ->set('body', 'Still there?')
            ->call('send')
            ->assertForbidden();

        $this->assertSame(0, Message::count());
    }

    public function test_coach_can_change_what_an_archived_client_can_do(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->archived('read_only')->create();

        Livewire::actingAs($coach)->test('pages::coach.clients.show', ['client' => $client])->call('setArchivedAccess', 'locked');

        $this->assertSame('locked', $client->fresh()->archived_access);
    }

    public function test_auto_archive_uses_each_coaches_window_and_default_access(): void
    {
        $coach = User::factory()->create(['auto_archive_days' => 30, 'archived_client_access' => 'locked']);
        $quiet = User::factory()->clientOf($coach)->create(['created_at' => now()->subDays(90), 'last_seen_at' => now()->subDays(45)]);
        $active = User::factory()->clientOf($coach)->create(['created_at' => now()->subDays(90), 'last_seen_at' => now()->subDays(2)]);
        $inPerson = User::factory()->clientOf($coach)->create(['created_at' => now()->subDays(90), 'last_seen_at' => null, 'in_person' => true]);
        Workout::factory()->forClient($inPerson, today()->subDays(5))->create(['completed_at' => now()->subDays(5)]);
        $newcomer = User::factory()->clientOf($coach)->invited()->create(['created_at' => now()->subDays(10)]);

        $otherCoach = User::factory()->create(); // auto-archive off
        $untouched = User::factory()->clientOf($otherCoach)->create(['created_at' => now()->subDays(400), 'last_seen_at' => now()->subDays(300)]);

        Artisan::call('clients:auto-archive');

        $this->assertTrue($quiet->fresh()->isArchived());
        $this->assertSame('inactive', $quiet->fresh()->archived_reason);
        $this->assertSame('locked', $quiet->fresh()->archived_access);
        $this->assertFalse($active->fresh()->isArchived());
        $this->assertFalse($inPerson->fresh()->isArchived(), 'Sessions a trainer logs count as activity.');
        $this->assertFalse($newcomer->fresh()->isArchived());
        $this->assertFalse($untouched->fresh()->isArchived());
    }

    public function test_coach_sets_their_archiving_preferences(): void
    {
        $coach = User::factory()->create();

        Livewire::actingAs($coach)->test('pages::coach.clients.index')
            ->set('autoArchiveDays', '60')
            ->set('archivedClientAccess', 'locked')
            ->call('saveArchiving')
            ->assertHasNoErrors();

        $coach->refresh();
        $this->assertSame(60, $coach->auto_archive_days);
        $this->assertSame('locked', $coach->archived_client_access);

        Livewire::actingAs($coach)->test('pages::coach.clients.index')->set('autoArchiveDays', '7')->call('saveArchiving')->assertHasErrors('autoArchiveDays');
    }

    public function test_coach_cannot_archive_another_coaches_client(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf(User::factory()->create())->create();

        $this->actingAs($coach)->get(route('coach.clients.show', $client))->assertForbidden();
    }

    public function test_delete_permanently_still_erases_the_client(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();

        Livewire::actingAs($coach)->test('pages::coach.clients.show', ['client' => $client])->call('removeClient');

        $this->assertModelMissing($client);
        $this->assertInstanceOf(Team::class, $coach->fresh()->team);
    }
}
