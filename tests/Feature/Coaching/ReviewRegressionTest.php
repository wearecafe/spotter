<?php

namespace Tests\Feature\Coaching;

use App\Models\Exercise;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\Program;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Services\ProgramImporter;
use App\Services\Progression;
use App\Support\Load;
use Database\Seeders\ExerciseLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regressions for issues found in code review.
 */
class ReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_used_invitation_cannot_be_replayed_to_reset_the_password(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->invited()->create();

        $stale = Livewire::test('pages::invitation.accept', ['token' => $client->invitation_token]);

        Livewire::test('pages::invitation.accept', ['token' => $client->invitation_token])
            ->set('password', 'first-password')->set('password_confirmation', 'first-password')->call('accept');
        auth()->logout();
        $hash = $client->fresh()->password;

        $stale->set('password', 'attacker-pass')->set('password_confirmation', 'attacker-pass')->call('accept')->assertNotFound();

        $this->assertSame($hash, $client->fresh()->password);
        $this->assertGuest();
    }

    public function test_reopening_and_completing_a_workout_does_not_duplicate_inbox_items(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $workout = Workout::factory()->forClient($client)->create();
        $workout->items()->create(['exercise_id' => Exercise::factory()->create()->id, 'sets' => 1]);

        $page = Livewire::actingAs($client)->test('pages::training.workout', ['workout' => $workout])->set('feedback', 'Tough one');
        $page->call('complete')->call('reopen')->call('complete');

        $this->assertSame(1, InboxItem::count());
    }

    public function test_finishing_an_import_twice_builds_programs_once(): void
    {
        $this->seed(ExerciseLibrarySeeder::class);
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create(['name' => 'Maya Chen']);
        $import = $coach->imports()->create(['source' => 'truecoach']);
        app(ProgramImporter::class)->addPrograms($import, [['name' => 'Maya Chen.txt', 'contents' => "Back Squat 3x5 60kg\n"]]);

        app(ProgramImporter::class)->finish($import);
        app(ProgramImporter::class)->finish($import->fresh());

        $this->assertSame(1, $client->programs()->count());
    }

    public function test_program_files_only_match_whole_client_names(): void
    {
        $coach = User::factory()->create();
        $sam = User::factory()->clientOf($coach)->create(['name' => 'Sam Lee']);
        $clients = $coach->clients()->get();
        $importer = app(ProgramImporter::class);

        $this->assertNull($importer->clientForFile('Sam_Leeson.txt', $clients));
        $this->assertTrue($importer->clientForFile('sam_lee - week 6.pdf', $clients)->is($sam));
        $this->assertTrue($importer->clientForFile('Sam Lee.txt', $clients)->is($sam));
    }

    public function test_multi_week_exports_keep_their_weeks(): void
    {
        $this->seed(ExerciseLibrarySeeder::class);
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create(['name' => 'Maya Chen']);
        $import = $coach->imports()->create(['source' => 'trainerize']);
        $text = "Week 1\nDay 1 - Lower\nBack Squat 3x5 60kg\nDay 2 - Upper\nBench Press 3x5 50kg\nWeek 2 - Day 1 Lower\nBack Squat 3x5 62.5kg\n";

        app(ProgramImporter::class)->addPrograms($import, [['name' => 'Maya Chen.txt', 'contents' => $text]]);
        app(ProgramImporter::class)->finish($import->fresh());

        $workouts = $client->programs()->sole()->workouts()->get();
        $this->assertSame([[1, 1], [1, 2], [2, 1]], $workouts->map(fn ($w) => [$w->week, $w->day])->all());
        $this->assertTrue($workouts[2]->scheduled_on->isSameDay($workouts[0]->scheduled_on->addWeek()));
        $this->assertFalse($workouts[0]->scheduled_on->isSameDay($workouts[1]->scheduled_on));
    }

    public function test_lifting_less_than_prescribed_holds_the_load(): void
    {
        $item = new WorkoutExercise(['sets' => 2, 'reps' => '5', 'load' => '75 kg', 'progression' => 'load',
            'logged_sets' => [['reps' => '5', 'load' => '60 kg', 'done' => true], ['reps' => '5', 'load' => '60 kg', 'done' => true]]]);

        $next = app(Progression::class)->nextPrescription($item);

        $this->assertSame('75 kg', $next['load']);
        $this->assertStringContainsString('under target', $next['change_reason']);
    }

    public function test_steps_in_other_units_are_converted(): void
    {
        $this->assertSame('140 lb', Load::add('135 lb', '2.5 kg'));
        $this->assertSame('102.5 kg', Load::add('100 kg', '5 lb'));
    }

    public function test_out_of_range_weeks_are_clamped(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $program = $coach->id ? Program::create(['coach_id' => $coach->id, 'client_id' => $client->id, 'title' => 'Block', 'starts_on' => today(), 'planned_weeks' => 4]) : null;
        app(Progression::class)->start($program, 2);

        Livewire::actingAs($coach)->test('pages::coach.program', ['program' => $program])
            ->set('week', 300)
            ->assertSet('week', 1)
            ->call('addDay');

        $this->assertSame([1], $program->workouts()->pluck('week')->unique()->values()->all());
    }

    public function test_tone_samples_never_come_from_other_clients(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"summary":"s","reply":"r","suggestion":null}']]])]);
        $coach = User::factory()->create();
        $maya = User::factory()->clientOf($coach)->create();
        $other = User::factory()->clientOf($coach)->create();
        Message::create(['coach_id' => $coach->id, 'client_id' => $other->id, 'sender_id' => $coach->id, 'body' => 'PRIVATE-NOTE-FOR-OTHER']);

        Livewire::actingAs($maya)->test('pages::training.messages')->set('body', 'Hi')->call('send');

        Http::assertSent(fn ($request) => ! str_contains(json_encode($request->data()), 'PRIVATE-NOTE-FOR-OTHER'));
    }
}
