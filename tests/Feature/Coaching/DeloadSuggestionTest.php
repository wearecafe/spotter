<?php

namespace Tests\Feature\Coaching;

use App\Models\Exercise;
use App\Models\InboxItem;
use App\Models\User;
use App\Models\Workout;
use App\Services\InboxFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeloadSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected User $coach;

    protected User $client;

    protected Exercise $bench;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(18, 0));
        $this->coach = User::factory()->create();
        $this->client = User::factory()->clientOf($this->coach)->create(['name' => 'Priya Shah']);
        $this->bench = Exercise::factory()->create(['name' => 'Bench Press']);
    }

    /**
     * @param  list<string|null>  $rpes  logged RPE per set
     */
    protected function complete(string $date, array $rpes, string $target = '7-8'): Workout
    {
        $workout = Workout::factory()->forClient($this->client, $date)->create();
        $workout->items()->create([
            'exercise_id' => $this->bench->id, 'sets' => count($rpes), 'reps' => '5', 'load' => '60 kg', 'target_rpe' => $target,
            'logged_sets' => array_map(fn ($rpe) => ['reps' => '5', 'load' => '60 kg', 'rpe' => $rpe, 'done' => true], $rpes),
        ]);
        $workout->update(['completed_at' => $date.' 18:00:00']);
        app(InboxFeed::class)->workoutCompleted($workout->fresh());

        return $workout;
    }

    protected function upcoming(string $date, ?string $load = '62.5 kg', int $sets = 3): Workout
    {
        $workout = Workout::factory()->forClient($this->client, $date)->create();
        $workout->items()->create(['exercise_id' => $this->bench->id, 'sets' => $sets, 'reps' => '5', 'load' => $load, 'target_rpe' => '7-8']);

        return $workout;
    }

    public function test_two_sessions_over_target_rpe_suggest_a_lighter_next_session_the_coach_can_apply(): void
    {
        $next = $this->upcoming('2026-11-06');

        $this->complete('2026-10-28', ['8', '8.5', '9']);
        $this->assertSame(0, InboxItem::where('type', 'deload')->count(), 'One hard session is not a pattern.');

        $this->complete('2026-11-04', ['8', '9', '9']);

        $item = InboxItem::where('type', 'deload')->sole();
        $this->assertSame(75, $item->priority);
        $this->assertSame('rules', $item->drafted_by);
        $this->assertSame([
            'workout_exercise_id' => $next->items->first()->id,
            'exercise' => 'Bench Press',
            'field' => 'load',
            'from' => '62.5 kg',
            'to' => '57.5 kg',
            'reason' => 'RPE above the 7-8 target two sessions running: lighten the next one.',
        ], $item->suggestion);
        $this->assertStringContainsString('lightening the next one', $item->draft_reply);

        Livewire::actingAs($this->coach)->test('pages::coach.inbox')->call('applySuggestion', $item->id);

        $this->assertSame('57.5 kg', $next->items()->sole()->load);
        $this->assertTrue($item->fresh()->suggestion['applied']);
    }

    public function test_no_suggestion_when_rpe_was_on_target_or_not_logged(): void
    {
        $this->upcoming('2026-11-06');

        $this->complete('2026-10-21', ['9', '9']);
        $this->complete('2026-10-28', ['7', '8']); // back on target
        $this->complete('2026-11-04', ['9', '9.5']);

        $this->assertSame(0, InboxItem::where('type', 'deload')->count());

        // Sessions without RPE logged are skipped when looking back, not counted as easy.
        $this->complete('2026-11-04', [null, null]);
        $this->assertSame(0, InboxItem::where('type', 'deload')->count());
    }

    public function test_one_suggestion_per_exercise_per_fortnight(): void
    {
        $this->upcoming('2026-11-08');

        $this->complete('2026-10-28', ['9']);
        $this->complete('2026-11-01', ['9']);
        $this->complete('2026-11-04', ['9.5']);

        $this->assertSame(1, InboxItem::where('type', 'deload')->count());
    }

    public function test_bodyweight_exercise_suggests_a_set_fewer(): void
    {
        $this->upcoming('2026-11-06', load: null, sets: 4);

        $this->complete('2026-10-28', ['9']);
        $this->complete('2026-11-04', ['9']);

        $suggestion = InboxItem::where('type', 'deload')->sole()->suggestion;
        $this->assertSame(['sets', '4', '3'], [$suggestion['field'], $suggestion['from'], $suggestion['to']]);
    }

    public function test_without_an_upcoming_session_it_still_flags_but_suggests_nothing(): void
    {
        $this->complete('2026-10-28', ['9']);
        $this->complete('2026-11-04', ['9']);

        $item = InboxItem::where('type', 'deload')->sole();
        $this->assertNull($item->suggestion);
        $this->assertStringContainsString('no upcoming session', $item->summary);
    }
}
