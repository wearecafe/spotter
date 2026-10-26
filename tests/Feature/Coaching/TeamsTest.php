<?php

namespace Tests\Feature\Coaching;

use App\Mail\TeamInvitationMail;
use App\Models\Exercise;
use App\Models\InboxItem;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\Workout;
use App\Services\TeamManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class TeamsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $coach;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Gym Owner']);
        $this->team = $this->owner->team;
        $this->coach = $this->joinTeam(User::factory()->create(['name' => 'Coach Sam']), 'coach');
    }

    protected function joinTeam(User $coach, string $role): User
    {
        $coach->team?->delete();
        $coach->forceFill(['team_id' => $this->team->id, 'team_role' => $role])->save();

        return $coach->fresh();
    }

    public function test_every_new_coach_gets_their_own_workspace_and_clients_join_it(): void
    {
        $solo = User::factory()->create();
        $client = User::factory()->clientOf($solo)->create();

        $this->assertSame('owner', $solo->team_role);
        $this->assertSame($solo->id, $solo->team->owner_id);
        $this->assertSame($solo->team_id, $client->fresh()->team_id);
    }

    public function test_admins_see_every_team_client_and_coaches_see_their_own(): void
    {
        $samsClient = User::factory()->clientOf($this->coach)->create(['name' => 'Sam Client']);
        $ownersClient = User::factory()->clientOf($this->owner)->create(['name' => 'Owner Client']);

        $this->actingAs($this->owner)->get(route('coach.clients.index'))->assertSee('Sam Client')->assertSee('Owner Client');
        $this->actingAs($this->coach)->get(route('coach.clients.index'))->assertSee('Sam Client')->assertDontSee('Owner Client');

        $this->actingAs($this->owner)->get(route('coach.clients.show', $samsClient))->assertOk();
        $this->actingAs($this->coach)->get(route('coach.clients.show', $ownersClient))->assertForbidden();
    }

    public function test_other_teams_stay_out(): void
    {
        $outsider = User::factory()->create();
        $client = User::factory()->clientOf($this->coach)->create();
        $workout = Workout::factory()->forClient($client)->create();

        $this->actingAs($outsider)->get(route('coach.clients.show', $client))->assertForbidden();
        $this->actingAs($outsider)->get(route('coach.workouts.edit', $workout))->assertForbidden();
        $this->actingAs($outsider)->get(route('coach.team'))->assertOk()->assertDontSee('Coach Sam');
    }

    public function test_admins_program_team_clients_as_their_coach(): void
    {
        $client = User::factory()->clientOf($this->coach)->create();

        Livewire::actingAs($this->owner)->test('pages::coach.clients.show', ['client' => $client])
            ->set('scheduledOn', today()->toDateString())
            ->call('schedule');

        $this->assertSame($this->coach->id, Workout::sole()->coach_id, 'Workouts belong to the client’s own coach.');
        $this->actingAs($this->owner)->get(route('coach.workouts.edit', Workout::sole()))->assertOk();
    }

    public function test_reassigning_a_client_moves_upcoming_work_and_inbox_items(): void
    {
        $client = User::factory()->clientOf($this->coach)->create();
        $done = Workout::factory()->forClient($client, today()->subDay())->create(['completed_at' => now()]);
        $next = Workout::factory()->forClient($client, today()->addDay())->create();
        $item = InboxItem::create(['coach_id' => $this->coach->id, 'client_id' => $client->id, 'type' => 'message', 'headline' => 'Hi']);

        Livewire::actingAs($this->owner)->test('pages::coach.clients.show', ['client' => $client])->call('reassign', $this->owner->id);

        $this->assertSame($this->owner->id, $client->fresh()->coach_id);
        $this->assertSame($this->owner->id, $next->fresh()->coach_id);
        $this->assertSame($this->coach->id, $done->fresh()->coach_id, 'History stays with whoever coached it.');
        $this->assertSame($this->owner->id, $item->fresh()->coach_id);
    }

    public function test_coaches_cannot_reassign_or_manage_the_team(): void
    {
        $client = User::factory()->clientOf($this->coach)->create();

        Livewire::actingAs($this->coach)->test('pages::coach.clients.show', ['client' => $client])->call('reassign', $this->owner->id)->assertForbidden();
        $this->actingAs($this->coach)->get(route('coach.team'))->assertForbidden();
    }

    public function test_reassigning_to_a_coach_outside_the_team_is_refused(): void
    {
        $client = User::factory()->clientOf($this->coach)->create();
        $outsider = User::factory()->create();

        Livewire::actingAs($this->owner)->test('pages::coach.clients.show', ['client' => $client])->call('reassign', $outsider->id)->assertNotFound();
        $this->assertSame($this->coach->id, $client->fresh()->coach_id);
    }

    public function test_team_shares_custom_exercises_and_templates(): void
    {
        $exercise = Exercise::factory()->create(['coach_id' => $this->coach->id, 'name' => 'Sled Push Sam']);
        Workout::factory()->create(['coach_id' => $this->coach->id, 'client_id' => null, 'title' => 'Sam’s Full Body']);

        $this->assertTrue(Exercise::query()->availableTo($this->owner)->whereKey($exercise->id)->exists());
        $this->assertFalse(Exercise::query()->availableTo(User::factory()->create())->whereKey($exercise->id)->exists());

        $this->actingAs($this->owner)->get(route('coach.templates'))->assertSee('Sam’s Full Body', false)->assertSee('By Coach Sam');
    }

    public function test_invited_coach_creates_an_account_and_joins(): void
    {
        Mail::fake();

        Livewire::actingAs($this->owner)->test('pages::coach.team')
            ->set('inviteEmail', 'New.Coach@Example.com')
            ->set('inviteRole', 'admin')
            ->call('invite')
            ->assertHasNoErrors();

        $invitation = TeamInvitation::sole();
        $this->assertSame('new.coach@example.com', $invitation->email);
        Mail::assertQueued(TeamInvitationMail::class);

        auth()->logout();
        $this->get(route('team-invitation.accept', $invitation->token))->assertOk()->assertSee($this->team->name);

        Livewire::test('pages::team-invitation.accept', ['token' => $invitation->token])
            ->set('name', 'New Coach')
            ->set('password', 'secret-password')
            ->set('password_confirmation', 'secret-password')
            ->call('register')
            ->assertRedirect(route('coach.dashboard'));

        $newCoach = User::firstWhere('email', 'new.coach@example.com');
        $this->assertSame([$this->team->id, 'admin'], [$newCoach->team_id, $newCoach->team_role]);
        $this->assertSame(0, Team::where('owner_id', $newCoach->id)->count(), 'No stray solo workspace.');
        $this->assertNotNull($invitation->fresh()->accepted_at);

        Livewire::test('pages::team-invitation.accept', ['token' => $invitation->token])->assertNotFound();
    }

    public function test_an_existing_solo_coach_brings_their_clients_when_joining(): void
    {
        $solo = User::factory()->create(['email' => 'solo@example.com']);
        $soloTeam = $solo->team;
        $client = User::factory()->clientOf($solo)->create();
        $invitation = TeamInvitation::create(['team_id' => $this->team->id, 'email' => 'solo@example.com', 'role' => 'coach', 'token' => 'tok', 'expires_at' => now()->addDay()]);

        Livewire::actingAs($solo)->test('pages::team-invitation.accept', ['token' => 'tok'])->call('join');

        $this->assertSame($this->team->id, $solo->fresh()->team_id);
        $this->assertSame($this->team->id, $client->fresh()->team_id);
        $this->assertNull(Team::find($soloTeam->id), 'The empty solo workspace is removed.');
        $this->actingAs($this->owner)->get(route('coach.clients.show', $client))->assertOk();
    }

    public function test_invitations_only_work_for_the_invited_email(): void
    {
        TeamInvitation::create(['team_id' => $this->team->id, 'email' => 'someone@example.com', 'role' => 'coach', 'token' => 'tok', 'expires_at' => now()->addDay()]);
        $wrong = User::factory()->create();

        Livewire::actingAs($wrong)->test('pages::team-invitation.accept', ['token' => 'tok'])
            ->assertSee('This invitation was sent to someone@example.com')
            ->call('join')
            ->assertForbidden();

        $this->assertNotSame($this->team->id, $wrong->fresh()->team_id);
    }

    public function test_expired_invitations_are_rejected(): void
    {
        TeamInvitation::create(['team_id' => $this->team->id, 'email' => 'late@example.com', 'role' => 'coach', 'token' => 'old', 'expires_at' => now()->subDay()]);

        $this->get(route('team-invitation.accept', 'old'))->assertNotFound();
    }

    public function test_removing_a_coach_hands_their_clients_over(): void
    {
        $client = User::factory()->clientOf($this->coach)->create();

        Livewire::actingAs($this->owner)->test('pages::coach.team')
            ->call('startRemove', $this->coach->id)
            ->set('handTo', (string) $this->owner->id)
            ->call('remove');

        $this->assertSame($this->owner->id, $client->fresh()->coach_id);
        $this->assertNotSame($this->team->id, $this->coach->fresh()->team_id);
        $this->assertSame('owner', $this->coach->fresh()->team_role);
    }

    public function test_the_owner_cannot_be_demoted_or_removed(): void
    {
        $admin = $this->joinTeam(User::factory()->create(), 'admin');

        Livewire::actingAs($admin)->test('pages::coach.team')->call('changeRole', $this->owner->id, 'coach')->assertForbidden();
        Livewire::actingAs($admin)->test('pages::coach.team')->call('startRemove', $this->owner->id)->assertForbidden();

        $this->assertSame('owner', $this->owner->fresh()->team_role);
    }

    public function test_messages_follow_the_client_after_reassignment(): void
    {
        $client = User::factory()->clientOf($this->coach)->create();
        Livewire::actingAs($client)->test('pages::training.messages')->set('body', 'Hello Sam')->call('send');

        app(TeamManager::class)->reassign($client->fresh(), $this->owner);

        $this->actingAs($this->owner)->get(route('coach.clients.messages', $client))->assertSee('Hello Sam');
    }
}
