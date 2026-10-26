<?php

namespace Tests\Feature\Coaching;

use App\Models\CheckIn;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\Workout;
use App\Services\NutritionImporter;
use App\Services\TeamManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * Regressions for issues found reviewing teams, nutrition and the backoffice.
 */
class TeamsReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $coach;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->coach = User::factory()->create();
        $this->coach->team->delete();
        $this->coach->forceFill(['team_id' => $this->owner->team_id, 'team_role' => 'coach'])->save();
    }

    public function test_a_removed_coach_loses_access_to_former_clients_finished_workouts(): void
    {
        $client = User::factory()->clientOf($this->coach)->create();
        $finished = Workout::factory()->forClient($client, today()->subDay())->create(['completed_at' => now()]);

        app(TeamManager::class)->removeCoach($this->coach->fresh(), $this->owner);

        $this->actingAs($this->coach->fresh())->get(route('coach.workouts.edit', $finished))->assertForbidden();
        $this->actingAs($this->owner)->get(route('coach.workouts.edit', $finished))->assertOk();
    }

    public function test_a_demoted_admin_cannot_keep_using_an_open_team_page(): void
    {
        $this->withoutExceptionHandling();
        $this->coach->forceFill(['team_role' => 'admin'])->save();
        $page = Livewire::actingAs($this->coach->fresh())->test('pages::coach.team');

        $this->coach->forceFill(['team_role' => 'coach'])->save();
        auth()->setUser($this->coach->fresh());

        try {
            $page->set('inviteEmail', 'alt@example.com')->set('inviteRole', 'admin')->call('invite');
            $this->fail('A demoted admin should not be able to act on the team page.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame(0, TeamInvitation::count());
    }

    public function test_a_reassigned_clients_old_coach_cannot_act_on_an_open_page(): void
    {
        $client = User::factory()->clientOf($this->coach)->create();
        $page = Livewire::actingAs($this->coach)->test('pages::coach.clients.show', ['client' => $client]);

        app(TeamManager::class)->reassign($client, $this->owner);

        $page->call('removeClient')->assertForbidden();
        $this->assertNotNull($client->fresh());
    }

    public function test_a_gym_coach_cannot_take_the_gyms_clients_to_another_team(): void
    {
        $client = User::factory()->clientOf($this->coach)->create();
        $otherTeam = User::factory()->create()->team;
        TeamInvitation::create(['team_id' => $otherTeam->id, 'email' => $this->coach->email, 'role' => 'coach', 'token' => 'tok', 'expires_at' => now()->addDay()]);

        Livewire::actingAs($this->coach)->test('pages::team-invitation.accept', ['token' => 'tok'])
            ->assertSee('Ask an admin there to remove you first')
            ->call('join')
            ->assertForbidden();

        $this->assertSame($this->owner->team_id, $client->fresh()->team_id);
        $this->assertSame($this->owner->team_id, $this->coach->fresh()->team_id);
    }

    public function test_oversized_zips_are_refused_before_decompressing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bomb').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('Nutrition-Summary.csv', "Date,Calories\n".str_repeat("2026-10-01,2000\n", 1_100_000)); // ~17 MB, compresses tiny
        $zip->close();

        $client = User::factory()->clientOf($this->owner)->create();

        Livewire::actingAs($client)->test('pages::training.nutrition')
            ->set('importFile', UploadedFile::fake()->createWithContent('export.zip', file_get_contents($path)))
            ->call('import')
            ->assertHasErrors('importFile');

        @unlink($path);
    }

    public function test_day_first_files_are_read_day_first(): void
    {
        $days = app(NutritionImporter::class)->parseCsv("Date,Calories\n05/10/2026,1900\n25/10/2026,2000\n")['days'];

        $this->assertSame(['2026-10-05', '2026-10-25'], array_keys($days));
    }

    public function test_previous_check_in_is_the_week_before(): void
    {
        $client = User::factory()->clientOf($this->owner)->create();
        $earlier = CheckIn::create(['client_id' => $client->id, 'week_of' => '2026-10-12', 'weight' => 65]);
        $later = CheckIn::create(['client_id' => $client->id, 'week_of' => '2026-10-19', 'weight' => 64.5]);

        $this->assertTrue($later->previous()->is($earlier));
        $this->assertNull($earlier->previous());
    }

    public function test_team_admins_can_open_team_clients_media(): void
    {
        Storage::fake('local');
        $client = User::factory()->clientOf($this->coach)->create();
        Storage::disk('local')->put('check-ins/x.jpg', 'img');
        $checkIn = CheckIn::create(['client_id' => $client->id, 'week_of' => today(), 'photos' => ['check-ins/x.jpg']]);

        $this->actingAs($this->owner)->get(route('media.check-in-photo', [$checkIn, 0]))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('media.check-in-photo', [$checkIn, 0]))->assertForbidden();
    }

    public function test_the_workspace_migration_covers_every_coach(): void
    {
        // Simulate legacy coaches with no team, more than one page of them.
        DB::table('users')->update(['team_id' => null, 'team_role' => null]);
        Team::query()->delete();
        foreach (range(1, 450) as $i) {
            DB::table('users')->insert(['name' => "Coach $i", 'email' => "c$i@example.com", 'role' => 'coach', 'created_at' => now(), 'updated_at' => now()]);
        }

        $migration = require database_path('migrations/2026_10_28_000001_create_teams_nutrition_and_backoffice_tables.php');
        (fn () => $this->giveEveryCoachAWorkspace())->call($migration);

        $this->assertSame(0, User::where('role', 'coach')->whereNull('team_id')->count());
        $this->assertSame(User::where('role', 'coach')->count(), Team::count());
    }
}
