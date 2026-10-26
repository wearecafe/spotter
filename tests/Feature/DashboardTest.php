<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_coaches_are_sent_to_the_coach_overview(): void
    {
        $coach = User::factory()->create();
        $this->actingAs($coach);

        $this->get(route('dashboard'))->assertRedirect(route('coach.dashboard'));
        $this->get(route('coach.dashboard'))->assertOk()->assertSee('Needs attention');
    }

    public function test_clients_are_sent_to_their_training_page(): void
    {
        $client = User::factory()->clientOf(User::factory()->create())->create();
        $this->actingAs($client);

        $this->get(route('dashboard'))->assertRedirect(route('training.index'));
        $this->get(route('training.index'))->assertOk()->assertSee('Today');
    }

    public function test_clients_cannot_reach_coach_pages(): void
    {
        $client = User::factory()->clientOf(User::factory()->create())->create();

        $this->actingAs($client)->get(route('coach.clients.index'))->assertForbidden();
    }
}
