<?php

namespace Tests\Feature\Coaching;

use App\Enums\Role;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_creates_a_coach(): void
    {
        $this->post(route('register.store'), [
            'name' => 'New Coach',
            'email' => 'new@coach.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertSame(Role::Coach, User::firstWhere('email', 'new@coach.test')->role);
    }

    public function test_coach_can_add_a_client_with_an_invitation(): void
    {
        $coach = User::factory()->create();
        $this->actingAs($coach);

        Livewire::test('pages::coach.clients.index')
            ->set('name', 'Maya Chen')
            ->set('email', 'maya@example.com')
            ->set('goal', 'First pull-up')
            ->call('addClient')
            ->assertHasNoErrors();

        $client = User::firstWhere('email', 'maya@example.com');

        $this->assertSame(Role::Client, $client->role);
        $this->assertSame($coach->id, $client->coach_id);
        $this->assertNull($client->password);
        $this->assertNotNull($client->invitation_token);

        $this->get(route('coach.clients.show', $client))
            ->assertOk()
            ->assertSee(route('invitation.accept', $client->invitation_token));
    }

    public function test_client_email_must_be_unique(): void
    {
        $coach = User::factory()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::actingAs($coach)->test('pages::coach.clients.index')
            ->set('name', 'Someone')
            ->set('email', 'taken@example.com')
            ->call('addClient')
            ->assertHasErrors('email');
    }

    public function test_invited_client_can_accept_and_set_a_password(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->invited()->create();

        $this->get(route('invitation.accept', $client->invitation_token))->assertOk()->assertSee($coach->name);

        Livewire::test('pages::invitation.accept', ['token' => $client->invitation_token])
            ->set('password', 'secret-password')
            ->set('password_confirmation', 'secret-password')
            ->call('accept')
            ->assertRedirect(route('training.index'));

        $client->refresh();
        $this->assertNull($client->invitation_token);
        $this->assertTrue($client->hasAcceptedInvitation());
        $this->assertAuthenticatedAs($client);
    }

    public function test_invalid_invitation_tokens_404(): void
    {
        $this->get(route('invitation.accept', 'not-a-real-token'))->assertNotFound();
    }

    public function test_coach_cannot_view_another_coaches_client(): void
    {
        $client = User::factory()->clientOf(User::factory()->create())->create();

        $this->actingAs(User::factory()->create())
            ->get(route('coach.clients.show', $client))
            ->assertForbidden();
    }

    public function test_coach_sees_only_their_own_clients(): void
    {
        $coach = User::factory()->create();
        User::factory()->clientOf($coach)->create(['name' => 'Mine Person']);
        User::factory()->clientOf(User::factory()->create())->create(['name' => 'Other Person']);

        $this->actingAs($coach)->get(route('coach.clients.index'))
            ->assertSee('Mine Person')
            ->assertDontSee('Other Person');
    }

    public function test_compliance_rate_counts_completed_past_workouts(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();

        $this->assertNull($client->complianceRate());

        Workout::factory()->forClient($client, today()->subDays(3))->create(['completed_at' => now()]);
        Workout::factory()->forClient($client, today()->subDays(2))->create();
        Workout::factory()->forClient($client, today()->addDays(2))->create();

        $this->assertSame(50, $client->complianceRate());
    }
}
