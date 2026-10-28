<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\Message;
use App\Models\Program;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\InboxFeed;
use App\Services\TeamManager;
use App\Services\TenantStats;
use App\Support\Demo;
use Database\Seeders\DemoTeamSeeder;
use Database\Seeders\ExerciseLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DemoTeamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The demo programs use exercises from the open library.
        ExerciseLibrarySeeder::$withOpenLibrary = true;
        $this->seed(ExerciseLibrarySeeder::class);
        config(['spotter.demo' => true]);
    }

    protected function demoUser(string $key): User
    {
        return User::where('email', DemoTeamSeeder::email($key))->sole();
    }

    public function test_demo_is_not_found_when_turned_off(): void
    {
        config(['spotter.demo' => false]);

        $this->get(route('demo'))->assertNotFound();
        $this->post(route('demo.login'), ['account' => 'alex'])->assertNotFound();
        $this->assertSame(0, Team::where('is_demo', true)->count());
        $this->get(route('home'))->assertOk()->assertDontSee(route('demo'));
    }

    public function test_first_visit_builds_the_demo_gym_once_and_lists_its_accounts(): void
    {
        $this->get(route('home'))->assertOk()->assertSee(route('demo'));

        $this->get(route('demo'))
            ->assertOk()
            ->assertSee('North Shore Strength')
            ->assertSee('Alex Rivera')->assertSee('Gym owner')
            ->assertSee('Sam Patel')
            ->assertSee('Maya Chen')
            ->assertSee('Luc Tremblay');

        $this->get(route('demo'))->assertOk();

        $team = Demo::team();
        $this->assertSame(1, Team::where('is_demo', true)->count());
        $this->assertSame('team', $team->plan);
        $this->assertSame(3, $team->coaches()->count());
        $this->assertSame(9, $team->clients()->count());
        $this->assertTrue(User::where('team_id', $team->id)->get()->every(fn (User $u) => Demo::isDemoEmail($u->email)));
    }

    public function test_the_demo_gym_has_work_waiting_for_each_coach(): void
    {
        $this->seed(DemoTeamSeeder::class);

        $alex = $this->demoUser('alex');
        $sam = $this->demoUser('sam');
        $maya = $this->demoUser('maya');

        $this->assertTrue($alex->inboxItems()->where('type', 'message')->exists());
        $this->assertTrue($alex->inboxItems()->where('type', 'check_in')->exists());
        $this->assertTrue($sam->inboxItems()->where('type', 'missed')->where('client_id', $this->demoUser('daniel')->id)->exists());
        $this->assertTrue(InboxItem::whereIn('coach_id', [$alex->id, $sam->id])->get()->every(fn (InboxItem $i) => $i->summary !== null), 'Every item is drafted.');

        $program = Program::where('client_id', $maya->id)->sole();
        $this->assertTrue($program->workouts()->whereNull('completed_at')->where('scheduled_on', '>=', today())->exists(), 'Maya has a session to do.');
        $this->assertSame(0, $program->workouts()->whereNull('completed_at')->where('scheduled_on', '<', today())->count(), 'Maya hasn’t missed anything.');
        $this->assertSame(0, Message::whereNull('notified_at')->count(), 'The unread-message emailer has nothing to send.');

        $this->assertSame(0, $this->demoUser('luc')->programs()->sole()->workouts()->where('week', 1)->whereNull('completed_at')->count());
        $this->assertNotNull($this->demoUser('noah')->invitation_token);
        $this->assertNull($this->demoUser('noah')->invitation_accepted_at);
    }

    public function test_visitors_sign_in_with_one_click_and_see_the_banner(): void
    {
        $this->seed(DemoTeamSeeder::class);

        $this->post(route('demo.login'), ['account' => 'alex'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->demoUser('alex'));
        $this->get(route('coach.dashboard'))->assertOk()->assertSee('You’re exploring a demo gym as Alex Rivera.', false);
        $this->get(route('coach.team'))->assertOk()->assertSee('Sam Patel');

        $this->post(route('demo.login'), ['account' => 'maya'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->demoUser('maya'));
        $this->get(route('training.index'))->assertOk()->assertSee('demo-banner', false);

        $this->get(route('demo.leave'))->assertRedirect(route('register'));
        $this->assertGuest();
    }

    public function test_demo_sign_in_only_reaches_demo_accounts(): void
    {
        $this->seed(DemoTeamSeeder::class);
        $real = User::factory()->create(['email' => 'real@coach.test']);

        $this->post(route('demo.login'), ['account' => 'jess'])->assertNotFound();
        $this->post(route('demo.login'), ['account' => 'real'])->assertNotFound();
        $this->post(route('demo.login'), ['account' => 'staff'])->assertNotFound();
        $this->assertGuest();

        // A real coach signed in elsewhere is unaffected by someone else's demo.
        $this->actingAs($real)->get(route('coach.dashboard'))->assertOk()->assertDontSee('demo-banner', false);
    }

    public function test_demo_accounts_cannot_sign_in_with_a_password(): void
    {
        $this->seed(DemoTeamSeeder::class);
        $alex = $this->demoUser('alex');
        $alex->forceFill(['password' => 'known-password'])->save();

        $this->post(route('login.store'), ['email' => $alex->email, 'password' => 'known-password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // Real accounts still can.
        $real = User::factory()->create(['email' => 'real@coach.test']);
        $this->post(route('login.store'), ['email' => 'real@coach.test', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($real);
    }

    public function test_demo_accounts_cannot_change_account_settings(): void
    {
        $this->seed(DemoTeamSeeder::class);
        $alex = $this->demoUser('alex');

        Livewire::actingAs($alex)->test('pages::settings.profile')
            ->set('name', 'Hijacked')
            ->call('updateProfileInformation');
        $this->assertSame('Alex Rivera', $alex->fresh()->name);

        Livewire::actingAs($alex)->test('pages::settings.delete-user-modal')
            ->set('password', 'anything')
            ->call('deleteUser');
        $this->assertNotNull($alex->fresh());

        $this->actingAs($alex)->from(route('profile.edit'))->post(route('two-factor.enable'))
            ->assertRedirect(route('profile.edit'));
        $this->assertNull($alex->fresh()->two_factor_secret);
        $this->actingAs($alex)->post(route('password.confirm.store'), ['password' => 'anything'])->assertSessionMissing('auth.password_confirmed_at');
    }

    public function test_the_demo_never_sends_email(): void
    {
        $this->seed(DemoTeamSeeder::class);
        config(['mail.default' => 'array']);
        $alex = $this->demoUser('alex');

        Livewire::actingAs($alex)->test('pages::coach.team')
            ->set('inviteEmail', 'someone@real-person.test')
            ->call('invite');
        $this->assertFalse(TeamInvitation::where('email', 'someone@real-person.test')->exists());

        // Anything addressed to a demo account is dropped, whoever sends it.
        auth()->logout();
        Mail::raw('Hello', fn ($m) => $m->to(DemoTeamSeeder::email('maya')));
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());

        Mail::raw('Hello', fn ($m) => $m->to('real@coach.test'));
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_visitor_activity_uses_rule_based_drafts_not_claude(): void
    {
        $this->seed(DemoTeamSeeder::class);
        config(['services.anthropic.key' => 'test-key', 'queue.default' => 'sync']);
        Http::fake();

        $maya = $this->demoUser('maya');
        $item = app(InboxFeed::class)->checkInSubmitted($maya->checkIns()->create(['week_of' => today()->addWeek()->startOfWeek(), 'energy' => 3, 'notes' => 'Visitor typed this']));

        Http::assertNothingSent();
        $this->assertNotNull($item->fresh()->summary);
    }

    public function test_reset_rebuilds_only_the_demo_gym(): void
    {
        Storage::fake(config('filesystems.media_disk'));
        $real = User::factory()->create(['email' => 'real@coach.test']);
        $realClient = User::factory()->clientOf($real)->create();

        $this->artisan('spotter:demo:reset', ['--force' => true])->assertSuccessful();
        $first = Demo::teamId();

        // A visitor changes things and uploads a photo.
        $maya = $this->demoUser('maya');
        Storage::disk(config('filesystems.media_disk'))->put('messages/'.$maya->id.'/photo.jpg', 'x');
        Message::create(['coach_id' => $maya->coach_id, 'client_id' => $maya->id, 'sender_id' => $maya->id, 'body' => 'Visitor message', 'photos' => ['messages/'.$maya->id.'/photo.jpg']]);
        $this->demoUser('alex')->team->update(['name' => 'Renamed by a visitor']);

        $this->artisan('spotter:demo:reset', ['--force' => true])->assertSuccessful();

        $this->assertNotSame($first, Demo::teamId());
        $this->assertSame(1, Team::where('is_demo', true)->count());
        $this->assertSame(DemoTeamSeeder::TEAM_NAME, Demo::team()->name);
        $this->assertFalse(Message::where('body', 'Visitor message')->exists());
        Storage::disk(config('filesystems.media_disk'))->assertMissing('messages/'.$maya->id.'/photo.jpg');

        $this->assertNotNull($real->fresh());
        $this->assertNotNull($realClient->fresh());
        $this->assertSame($real->team_id, $realClient->fresh()->team_id);
    }

    public function test_reset_recovers_coaches_a_visitor_removed_from_the_team(): void
    {
        $this->seed(DemoTeamSeeder::class);
        $sam = $this->demoUser('sam');

        app(TeamManager::class)->removeCoach($sam, $this->demoUser('alex'));
        $this->assertFalse(Demo::isDemoUser($sam->fresh()), 'Sam now has a solo workspace of his own.');

        $this->artisan('spotter:demo:reset', ['--force' => true])->assertSuccessful();

        $this->assertTrue(Demo::isDemoUser($this->demoUser('sam')));
        $this->assertSame(1, Team::count(), 'Sam’s solo workspace is gone too.');
    }

    public function test_first_visit_build_skips_claude_and_never_builds_twice(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake();

        $this->get(route('demo'))->assertOk();
        $id = Demo::teamId();
        $this->artisan('spotter:demo:reset', ['--if-missing' => true])->assertSuccessful();

        $this->assertSame($id, Demo::teamId());
        Http::assertNothingSent();
    }

    public function test_nightly_rebuild_drafts_the_inbox_with_claude_when_configured(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"summary": "Drafted by Claude.", "reply": "Hi!", "suggestion": null}']]])]);

        $this->artisan('spotter:demo:reset', ['--force' => true])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));
        $this->assertTrue(InboxItem::where('summary', 'Drafted by Claude.')->exists());
    }

    public function test_demo_domain_is_reserved(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Sneaky', 'email' => 'alex@'.Demo::DOMAIN, 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertSessionHasErrors('email');
    }

    public function test_backoffice_totals_leave_out_the_demo_gym(): void
    {
        $this->seed(DemoTeamSeeder::class);
        User::factory()->create();

        $stats = app(TenantStats::class)->platform();

        $this->assertSame(1, $stats['teams']);
        $this->assertSame(1, $stats['coaches']);
        $this->assertSame(0, $stats['clients']);
        $this->assertSame(0, $stats['workouts_7d']);
    }
}
