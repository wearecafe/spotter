<?php

namespace Tests\Feature;

use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class BackofficeTest extends TestCase
{
    use RefreshDatabase;

    protected User $staff;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['email' => 'ops@spotter.test']);
        $this->staff->forceFill(['is_staff' => true])->save();
        $this->owner = User::factory()->create(['name' => 'Gym Owner', 'email' => 'owner@gym.test']);
        $this->owner->team->update(['name' => 'North Shore Strength']);
    }

    public function test_only_staff_can_open_the_backoffice(): void
    {
        $this->get(route('admin.tenants'))->assertRedirect(route('login'));
        $this->actingAs($this->owner)->get(route('admin.tenants'))->assertNotFound();

        $this->actingAs($this->staff)->get(route('admin.tenants'))->assertOk()->assertSee('North Shore Strength')->assertSee('owner@gym.test');
    }

    public function test_tenant_detail_shows_counts_but_never_client_names(): void
    {
        $client = User::factory()->clientOf($this->owner)->create(['name' => 'Private Client Name', 'email' => 'private@client.test']);

        $this->actingAs($this->staff)->get(route('admin.tenants.show', $this->owner->team))
            ->assertOk()
            ->assertSee('Gym Owner')
            ->assertDontSee('Private Client Name')
            ->assertDontSee('private@client.test');
    }

    public function test_staff_change_plan_comp_and_notes_with_an_audit_trail(): void
    {
        $team = $this->owner->team;

        Livewire::actingAs($this->staff)->test('pages::admin.tenant', ['team' => $team])
            ->set('plan', 'coach')->call('savePlan')
            ->set('compMonths', 3)->call('comp')
            ->set('notes', 'Founding coach, migrated from Trainerize')->call('saveNotes');

        $team->refresh();
        $this->assertSame('coach', $team->plan);
        $this->assertTrue($team->comp_until->isSameDay(today()->addMonths(3)));
        $this->assertSame(['plan_changed', 'comp_granted', 'notes_updated'], StaffAuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame($this->staff->id, StaffAuditLog::first()->staff_id);

        $this->actingAs($this->staff)->get(route('admin.audit'))->assertSee('plan changed')->assertSee('ops@spotter.test');
    }

    public function test_suspending_signs_out_coaches_and_clients_until_reactivated(): void
    {
        $client = User::factory()->clientOf($this->owner)->create();
        $team = $this->owner->team;

        Livewire::actingAs($this->staff)->test('pages::admin.tenant', ['team' => $team])
            ->call('suspend')->assertHasErrors('suspendReason')
            ->set('suspendReason', 'Chargeback')->call('suspend')->assertHasNoErrors();

        $this->assertTrue($team->fresh()->isSuspended());

        $this->actingAs($this->owner->fresh())->get(route('coach.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->actingAs($client->fresh())->get(route('training.index'))->assertRedirect(route('login'));

        Livewire::actingAs($this->staff)->test('pages::admin.tenant', ['team' => $team->fresh()])->call('reactivate');
        $this->actingAs($this->owner->fresh())->get(route('coach.dashboard'))->assertOk();
    }

    public function test_staff_can_send_the_owner_a_password_reset(): void
    {
        Notification::fake();

        Livewire::actingAs($this->staff)->test('pages::admin.tenant', ['team' => $this->owner->team])->call('sendOwnerReset');

        Notification::assertSentTo($this->owner, ResetPassword::class);
        $this->assertSame('password_reset_sent', StaffAuditLog::sole()->action);
    }

    public function test_non_staff_cannot_run_backoffice_actions(): void
    {
        Livewire::actingAs($this->owner)->test('pages::admin.tenant', ['team' => $this->owner->team])->assertNotFound();
    }

    public function test_tenant_list_filters_and_estimates_revenue(): void
    {
        $this->owner->team->update(['plan' => 'coach']);
        User::factory()->count(17)->clientOf($this->owner)->create();
        $other = User::factory()->create(['name' => 'Solo Sally']);
        $other->team->update(['suspended_at' => now(), 'suspended_reason' => 'test']);

        Livewire::actingAs($this->staff)->test('pages::admin.tenants')
            ->assertSee('$42.00') // $39 + 2 extra clients × $1.50
            ->set('status', 'suspended')
            ->assertSee('Solo Sally')
            ->assertDontSee('North Shore Strength')
            ->set('status', '')
            ->set('search', 'owner@gym')
            ->assertSee('North Shore Strength')
            ->assertDontSee('Solo Sally');
    }

    public function test_staff_command_grants_and_revokes_access(): void
    {
        $this->artisan('spotter:staff', ['email' => 'OWNER@gym.test'])->assertSuccessful();
        $this->assertTrue($this->owner->fresh()->is_staff);

        $this->artisan('spotter:staff', ['email' => 'owner@gym.test', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($this->owner->fresh()->is_staff);

        $this->artisan('spotter:staff', ['email' => 'nobody@x.test'])->assertFailed();
    }

    public function test_staff_flag_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();
        $user->update(['is_staff' => true, 'team_role' => 'owner', 'team_id' => 999]);

        $this->assertFalse($user->fresh()->is_staff);
    }
}
