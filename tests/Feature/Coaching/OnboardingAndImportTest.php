<?php

namespace Tests\Feature\Coaching;

use App\Mail\ClientInvitation;
use App\Models\Exercise;
use App\Models\Import;
use App\Models\User;
use App\Services\ProgramImporter;
use Database\Seeders\ExerciseLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class OnboardingAndImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $coach;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExerciseLibrarySeeder::class);
        $this->coach = User::factory()->create(['name' => 'Alex Rivera']);
    }

    protected function csv(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('clients.csv', "First Name,Last Name,Email,Language\nMaya,Chen,maya@example.com,English\nLuc,Tremblay,luc@example.com,Français\n");
    }

    protected function programFile(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "Day 1 - Lower A\nBack Squat 4x5 75kg rpe 7-8\nRDL 3x9 60kg\nBSS 3x8 16kg DBs\nCopenhagen 3x20s\nDay 2 - Upper\nOHP 4x6 40kg\nLat PD 3x10-12 50kg\n");
    }

    public function test_new_coaches_are_prompted_to_set_up(): void
    {
        $this->actingAs($this->coach)->get(route('coach.dashboard'))->assertSee('Finish setting up Spotter');
        $this->actingAs($this->coach)->get(route('coach.setup'))->assertOk()->assertSee('Tell us about your coaching');
    }

    public function test_business_step_saves_the_profile(): void
    {
        Livewire::actingAs($this->coach)->test('pages::coach.setup')
            ->set('businessName', 'North Shore Strength')
            ->set('languages', 'both')
            ->set('clientCount', '16-50')
            ->call('saveBusiness')
            ->assertHasNoErrors()
            ->assertSet('step', 2)
            ->assertSet('subdomain', 'north-shore-strength');

        $this->coach->refresh();
        $this->assertSame('North Shore Strength', $this->coach->business_name);
        $this->assertSame('both', $this->coach->coaching_languages);
    }

    public function test_importing_clients_and_programs_then_reviewing_matches(): void
    {
        Livewire::actingAs($this->coach)->test('pages::coach.setup', ['step' => 2])
            ->set('source', 'trainerize')
            ->set('monthsLeft', 7)
            ->set('clientsFile', $this->csv())
            ->set('programFiles', [$this->programFile('Maya Chen.txt'), $this->programFile('Someone Else.txt')])
            ->call('import')
            ->assertHasNoErrors()
            ->assertRedirect(route('coach.imports.show', Import::sole()));

        $this->assertSame(['luc@example.com', 'maya@example.com'], $this->coach->clients()->orderBy('email')->pluck('email')->all());
        $this->assertSame('fr', User::firstWhere('email', 'luc@example.com')->locale);
        $this->assertSame(7, $this->coach->fresh()->contract_months_left);

        $import = Import::sole();
        $this->assertTrue($import->programs['unmatched:someone else']['unreadable']);
        $this->assertSame('Romanian Deadlift', $import->matches['RDL']['suggestion']);
        $this->assertTrue($import->matches['RDL']['resolved']);
        $this->assertSame('none', $import->matches['Copenhagen']['confidence']);
        $this->assertSame(1, $import->unresolvedCount());

        $this->actingAs($this->coach)->get(route('coach.imports.show', $import))->assertOk()->assertSee('Copenhagen')->assertSee('Not included');

        $review = Livewire::actingAs($this->coach)->test('pages::coach.import', ['import' => $import])
            ->call('finish');
        $this->assertSame('review', $import->fresh()->status, 'Cannot finish with unconfirmed names.');

        $review->call('createCustom', 'Copenhagen')->call('finish')->assertRedirect(route('coach.setup', ['step' => 3]));

        $this->assertTrue(Exercise::where('coach_id', $this->coach->id)->where('name', 'Copenhagen')->exists());

        $maya = User::firstWhere('email', 'maya@example.com');
        $program = $maya->programs()->sole();
        $this->assertSame('Imported from ABC Trainerize', $program->title);
        $days = $program->workouts()->with('items.exercise')->get();
        $this->assertSame(['Day 1 - Lower A', 'Day 2 - Upper'], $days->pluck('title')->all());
        $squat = $days[0]->items->first();
        $this->assertSame('Back Squat', $squat->exercise->name);
        $this->assertSame([4, '5', '75 kg', '7-8', 'load'], [$squat->sets, $squat->reps, $squat->load, $squat->target_rpe, $squat->progression]);
        $this->assertCount(4, $days[0]->items);
        $this->assertSame('finished', $import->fresh()->status);
    }

    public function test_existing_accounts_are_never_taken_over_by_an_import(): void
    {
        $other = User::factory()->create(['email' => 'maya@example.com']);

        $created = app(ProgramImporter::class)->createClients($this->coach, collect([['name' => 'Maya Chen', 'email' => 'maya@example.com', 'locale' => 'en']]));

        $this->assertSame(0, $created);
        $this->assertNull($other->fresh()->coach_id);
    }

    public function test_a_csv_without_emails_is_rejected_with_a_clear_message(): void
    {
        Livewire::actingAs($this->coach)->test('pages::coach.setup', ['step' => 2])
            ->set('clientsFile', UploadedFile::fake()->createWithContent('clients.csv', "Name,Phone\nMaya,555\n"))
            ->call('import')
            ->assertHasErrors('clientsFile');
    }

    public function test_imports_belong_to_their_coach(): void
    {
        $import = $this->coach->imports()->create(['source' => 'truecoach']);

        $this->actingAs(User::factory()->create())->get(route('coach.imports.show', $import))->assertForbidden();
    }

    public function test_brand_step_validates_and_saves(): void
    {
        User::factory()->create(['subdomain' => 'taken']);

        Livewire::actingAs($this->coach)->test('pages::coach.setup', ['step' => 4])
            ->set('subdomain', 'taken')
            ->call('saveBrand')
            ->assertHasErrors('subdomain')
            ->set('subdomain', 'North Shore')
            ->set('brandColor', '#1d5fd1')
            ->call('saveBrand')
            ->assertHasNoErrors()
            ->assertSet('step', 5);

        $this->assertSame(['north-shore', '#1D5FD1'], [$this->coach->fresh()->subdomain, $this->coach->fresh()->brand_color]);
    }

    public function test_invites_go_out_in_each_clients_language_and_finish_setup(): void
    {
        Mail::fake();
        $maya = User::factory()->clientOf($this->coach)->invited()->create(['name' => 'Maya Chen']);
        $luc = User::factory()->clientOf($this->coach)->invited()->create(['name' => 'Luc Tremblay', 'locale' => 'fr']);

        Livewire::actingAs($this->coach)->test('pages::coach.setup', ['step' => 5])
            ->assertSee('Luc Tremblay')
            ->set("invite.{$maya->id}", false)
            ->call('sendInvites')
            ->assertRedirect(route('coach.dashboard'));

        Mail::assertQueued(ClientInvitation::class, fn ($mail) => $mail->hasTo($luc->email));
        Mail::assertNotQueued(ClientInvitation::class, fn ($mail) => $mail->hasTo($maya->email));
        $this->assertNotNull($luc->fresh()->invited_at);
        $this->assertNotNull($this->coach->fresh()->onboarded_at);

        $this->coach->update(['business_name' => 'North Shore Strength', 'import_source' => 'trainerize']);
        $rendered = (new ClientInvitation($luc->fresh()))->locale('fr')->render();
        $this->assertStringContainsString('Spotter', $rendered);
        $this->assertStringContainsString('Commencer', $rendered);
    }

    public function test_the_invite_page_switches_between_english_and_french(): void
    {
        $this->coach->update(['business_name' => 'North Shore Strength', 'import_source' => 'trainerize', 'brand_color' => '#1D5FD1']);
        $client = User::factory()->clientOf($this->coach)->invited()->create();

        $this->get(route('invitation.accept', $client->invitation_token))
            ->assertOk()
            ->assertSee('North Shore Strength')
            ->assertSee('Your coaching is moving to Spotter')
            ->assertSee('#1D5FD1');

        Livewire::test('pages::invitation.accept', ['token' => $client->invitation_token])
            ->call('switchLanguage', 'fr')
            ->assertSee('Ton coaching passe à Spotter')
            ->assertSee('Commencer');

        $this->assertSame('fr', $client->fresh()->locale);
    }

    public function test_parser_handles_common_export_formats(): void
    {
        $days = app(ProgramImporter::class)->parseProgram("Goblet squat 3 x 12 reps 2x16kg\nPull-ups 3x5 BW\nNotes: go slow\nPlank 3x30s");

        $this->assertSame('Day A', $days[0]['title']);
        $this->assertSame(['Goblet squat', 3, '12', '2x16 kg'], [$days[0]['rows'][0]['raw'], $days[0]['rows'][0]['sets'], $days[0]['rows'][0]['reps'], $days[0]['rows'][0]['load']]);
        $this->assertSame('BW', $days[0]['rows'][1]['load']);
        $this->assertSame('30s', $days[0]['rows'][2]['reps']);
        $this->assertCount(3, $days[0]['rows']);
    }
}
