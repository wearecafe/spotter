<?php

namespace Tests\Feature\Coaching;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Database\Seeders\ExerciseLibrarySeeder;
use Database\Seeders\FreeExerciseDbSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExerciseLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function seedLibrary(): void
    {
        ExerciseLibrarySeeder::$withOpenLibrary = true;
        $this->seed(ExerciseLibrarySeeder::class);
    }

    public function test_the_open_library_loads_once_and_enriches_starter_exercises(): void
    {
        $this->seedLibrary();
        $this->seed(FreeExerciseDbSeeder::class); // re-running must not duplicate

        $shared = Exercise::whereNull('coach_id');
        $this->assertSame(count(ExerciseLibrarySeeder::EXERCISES) + 876 - count(FreeExerciseDbSeeder::LINKS), $shared->count());
        $this->assertSame(0, Exercise::query()->selectRaw('lower(name) n, count(*) c')->whereNull('coach_id')->groupBy('n')->havingRaw('count(*) > 1')->count(), 'No duplicate names.');

        $squat = Exercise::firstWhere('name', 'Back Squat');
        $this->assertSame(['source' => 'spotter', 'source_id' => 'Barbell_Squat'], $squat->only('source', 'source_id'));
        $this->assertCount(2, $squat->imageUrls());
        $this->assertStringStartsWith('https://raw.githubusercontent.com/yuhonas/free-exercise-db/f00c92c7', $squat->imageUrls()[0]);
        $this->assertNotEmpty($squat->instructionSteps());

        $this->assertNull(Exercise::where('source', 'free-exercise-db')->where('source_id', 'Barbell_Squat')->first(), 'Linked twins are not added twice.');
        $this->assertSame('Mobility', Exercise::where('source_id', 'Worlds_Greatest_Stretch')->value('muscle_group'));
    }

    public function test_image_base_url_can_point_at_our_own_storage(): void
    {
        config(['services.exercise_images.base_url' => 'https://cdn.example.com/exercises/']);
        $exercise = new Exercise(['images' => ['Barbell_Squat/0.jpg']]);

        $this->assertSame(['https://cdn.example.com/exercises/Barbell_Squat/0.jpg'], $exercise->imageUrls());
    }

    public function test_coaches_browse_filter_and_view_the_library(): void
    {
        $this->seedLibrary();
        $coach = User::factory()->create();
        $squat = Exercise::firstWhere('name', 'Back Squat');

        $this->actingAs($coach)->get(route('coach.exercises'))->assertOk()->assertSee('883 exercises', false);

        Livewire::actingAs($coach)->test('pages::coach.exercises')
            ->set('search', 'Back Squat')
            ->assertSee('Back Squat')
            ->call('openExercise', $squat->id)
            ->assertDontSee('Photos and instructions from')
            ->assertSee($squat->imageUrls()[0])
            ->set('viewingId', null)
            ->set('search', '')
            ->set('equipmentFilter', 'Kettlebell')
            ->set('level', 'expert')
            ->assertDontSee('Back Squat');
    }

    public function test_copying_a_library_exercise_makes_an_editable_custom_one(): void
    {
        $this->seedLibrary();
        $coach = User::factory()->create();
        $squat = Exercise::firstWhere('name', 'Back Squat');

        Livewire::actingAs($coach)->test('pages::coach.exercises')->call('copyToMine', $squat->id)->assertSet('name', 'Back Squat');

        $copy = $coach->exercises()->sole();
        $this->assertSame($squat->images, $copy->images);
        $this->assertTrue($coach->can('update', $copy));
        $this->assertFalse($coach->can('update', $squat));
    }

    public function test_clients_see_how_to_photos_and_steps_in_their_workout(): void
    {
        $this->seedLibrary();
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $workout = Workout::factory()->forClient($client)->create();
        $squat = Exercise::firstWhere('name', 'Back Squat');
        $workout->items()->create(['exercise_id' => $squat->id, 'sets' => 3, 'reps' => '5']);

        $this->actingAs($client)->get(route('training.workout', $workout))
            ->assertOk()
            ->assertSee('How to')
            ->assertSee($squat->imageUrls()[1])
            ->assertSee($squat->instructionSteps()[0]);
    }

    public function test_pickers_put_names_starting_with_the_search_first(): void
    {
        $this->seedLibrary();
        $coach = User::factory()->create();
        $template = Workout::factory()->create(['coach_id' => $coach->id, 'client_id' => null]);

        $results = Livewire::actingAs($coach)->test('pages::coach.workout-builder', ['workout' => $template])
            ->set('exerciseSearch', 'Plank')
            ->instance()->exerciseResults;

        $this->assertSame('Plank', $results->first()->name);
    }
}
