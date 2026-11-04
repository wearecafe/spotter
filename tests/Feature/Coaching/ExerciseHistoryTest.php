<?php

namespace Tests\Feature\Coaching;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Services\ExerciseHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExerciseHistoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array{0: string|null, 1: string|null}>  $sets  [reps, load] per done set
     */
    protected function logSession(User $client, Exercise $exercise, string $date, array $sets, array $prescription = []): Workout
    {
        $workout = Workout::factory()->forClient($client, $date)->create(['completed_at' => $date.' 18:00:00']);
        $workout->items()->create(array_merge(['exercise_id' => $exercise->id, 'sets' => count($sets), 'reps' => '5', 'load' => null], $prescription, [
            'logged_sets' => array_map(fn ($s) => ['reps' => $s[0], 'load' => $s[1], 'rpe' => null, 'done' => true], $sets),
        ]));

        return $workout;
    }

    public function test_history_spans_programs_and_measures_heaviest_e1rm_and_volume(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $squat = Exercise::factory()->create(['name' => 'Back squat']);

        $this->logSession($client, $squat, '2026-09-01', [['5', '60 kg'], ['5', '60 kg'], ['5', '60 kg']]);
        $this->logSession($client, $squat, '2026-10-01', [['5', '80 kg'], ['3', '90 kg']]);
        $this->logSession($client, $squat, '2026-11-01', [['5', '85 kg'], ['5', '85 kg']]);
        // Not completed: ignored.
        Workout::factory()->forClient($client, '2026-11-03')->create()->items()->create(['exercise_id' => $squat->id, 'logged_sets' => [['reps' => '5', 'load' => '200 kg', 'done' => true]]]);

        $history = app(ExerciseHistory::class);

        $heaviest = $history->forExercise($client, $squat->id, 'heaviest');
        $this->assertSame(3, $heaviest['sessions']);
        $this->assertSame(60.0, $heaviest['first']['heaviest']);
        $this->assertSame(90.0, $heaviest['best']['heaviest']);
        $this->assertSame(85.0, $heaviest['latest']['heaviest']);
        $this->assertEquals(42, $heaviest['change']);

        $e1rm = $history->forExercise($client, $squat->id, 'e1rm');
        // Best e1RM: 85 × (1 + 5/30) = 99.17 beats 90 × (1 + 3/30) = 99.
        $this->assertSame(99.17, $e1rm['best']['e1rm']);

        $volume = $history->forExercise($client, $squat->id, 'volume');
        $this->assertEqualsWithDelta(900.0, $volume['first']['volume'], 0.01);
        $this->assertEqualsWithDelta(850.0, $volume['latest']['volume'], 0.01);
    }

    public function test_blank_load_and_reps_on_a_done_set_count_as_prescribed(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $press = Exercise::factory()->create();

        $this->logSession($client, $press, '2026-10-01', [[null, null], [null, null]], ['reps' => '8-10', 'load' => '135 lb']);

        $row = app(ExerciseHistory::class)->forExercise($client, $press->id, 'volume');

        $this->assertSame('lb', $row['unit']);
        $this->assertEqualsWithDelta(135 * 0.45359237 * 16, $row['latest']['volume'], 0.1);
        $this->assertSame('2,160 lb', app(ExerciseHistory::class)->format($row['latest']['volume'], 'lb'));
    }

    public function test_coach_sees_a_clients_progress_and_their_metric_choice_is_saved(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $deadlift = Exercise::factory()->create(['name' => 'Deadlift']);
        $this->logSession($client, $deadlift, '2026-09-01', [['5', '100 kg']]);
        $this->logSession($client, $deadlift, '2026-10-01', [['5', '120 kg']]);

        $this->actingAs($coach)->get(route('coach.clients.progress', $client))->assertOk()->assertSee('Deadlift')->assertSee('120 kg');

        Livewire::actingAs($coach)->test('pages::coach.clients.progress', ['client' => $client])
            ->set('metric', 'e1rm')
            ->assertSee('140 kg') // 120 × (1 + 5/30)
            ->set('exerciseId', $deadlift->id)
            ->assertSee(__('First'));

        $this->assertSame('e1rm', $coach->fresh()->history_metric);
    }

    public function test_coach_cannot_see_another_coaches_client_progress(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf(User::factory()->create())->create();

        $this->actingAs($coach)->get(route('coach.clients.progress', $client))->assertForbidden();
    }

    public function test_client_sees_their_own_progress_using_their_coaches_metric(): void
    {
        $coach = User::factory()->create(['history_metric' => 'volume']);
        $client = User::factory()->clientOf($coach)->create();
        $row = Exercise::factory()->create(['name' => 'Barbell row']);
        $this->logSession($client, $row, '2026-10-01', [['10', '50 kg'], ['10', '50 kg']]);

        $this->actingAs($client)->get(route('training.progress'))
            ->assertOk()
            ->assertSee('Barbell row')
            ->assertSee('1,000 kg')
            ->assertSee(mb_strtolower(__('Total volume')));
    }

    public function test_client_logger_shows_what_they_did_last_time(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();
        $bench = Exercise::factory()->create();
        $this->logSession($client, $bench, '2026-10-01', [['5', '70 kg'], ['5', '70 kg'], ['5', '70 kg']]);

        $today = Workout::factory()->forClient($client)->create();
        $today->items()->create(['exercise_id' => $bench->id, 'sets' => 3, 'reps' => '5', 'load' => '72.5 kg']);

        $this->actingAs($client)->get(route('training.workout', $today))
            ->assertOk()
            ->assertSee(__('Last time: :sets', ['sets' => '3 × 5 @ 70 kg']));
    }

    public function test_new_client_screens_render_in_french(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create(['locale' => 'fr']);
        $bench = Exercise::factory()->create();
        $this->logSession($client, $bench, '2026-10-01', [['5', '70 kg']]);
        $client->habits()->create(['created_by_id' => $coach->id, 'name' => 'Eau']);
        $today = Workout::factory()->forClient($client)->create();
        $today->items()->create(['exercise_id' => $bench->id, 'sets' => 1, 'reps' => '5']);

        $this->actingAs($client)->get(route('training.workout', $today))->assertOk()
            ->assertSee('La dernière fois : 1 × 5 @ 70 kg')
            ->assertSee('Ajouter une note pour ton coach');
        $this->actingAs($client)->get(route('training.progress'))->assertOk()->assertSee('Mes progrès');
        $this->actingAs($client)->get(route('training.index'))->assertOk()->assertSee('Habitudes');
    }
}
