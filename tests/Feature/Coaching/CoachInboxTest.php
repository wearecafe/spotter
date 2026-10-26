<?php

namespace Tests\Feature\Coaching;

use App\Models\Exercise;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\User;
use App\Models\Workout;
use App\Services\InboxFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CoachInboxTest extends TestCase
{
    use RefreshDatabase;

    protected User $coach;

    protected User $client;

    protected Workout $next;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coach = User::factory()->create(['name' => 'Alex Rivera']);
        $this->client = User::factory()->clientOf($this->coach)->create(['name' => 'Maya Chen']);

        $this->next = Workout::factory()->forClient($this->client, today()->addDay())->create(['title' => 'Lower A']);
        $this->next->items()->create(['exercise_id' => Exercise::factory()->create(['name' => 'Back Squat'])->id, 'sets' => 4, 'reps' => '5', 'load' => '75 kg']);
    }

    protected function submitCheckIn(int $energy, int $stress): void
    {
        Livewire::actingAs($this->client)->test('pages::training.check-in')
            ->set('weight', '64.2')
            ->set('energy', $energy)
            ->set('stress', $stress)
            ->set('sleepHours', '6')
            ->set('notes', 'Rough week at work')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('sent', true);
    }

    public function test_a_check_in_lands_in_the_inbox_with_a_rule_based_draft_and_suggestion(): void
    {
        $this->submitCheckIn(energy: 2, stress: 4);

        $item = InboxItem::sole();
        $this->assertSame('check_in', $item->type);
        $this->assertSame($this->coach->id, $item->coach_id);
        $this->assertSame('rules', $item->drafted_by);
        $this->assertStringContainsString('energy 2/5', $item->summary);
        $this->assertStringContainsString('Maya', $item->draft_reply);
        $this->assertSame('sets', $item->suggestion['field']);
        $this->assertSame('3', $item->suggestion['to']);

        $this->actingAs($this->coach)->get(route('coach.inbox'))
            ->assertOk()
            ->assertSee('Maya Chen')
            ->assertSee('Rough week at work');
    }

    public function test_a_good_week_gets_no_program_change(): void
    {
        $this->submitCheckIn(energy: 4, stress: 2);

        $this->assertNull(InboxItem::sole()->suggestion);
    }

    public function test_drafts_are_written_in_the_clients_language(): void
    {
        $this->client->update(['locale' => 'fr']);

        $this->submitCheckIn(energy: 4, stress: 2);

        $this->assertStringContainsString('Merci', InboxItem::sole()->draft_reply);
    }

    public function test_coach_approves_a_draft_which_sends_it_and_clears_the_item(): void
    {
        $this->submitCheckIn(energy: 2, stress: 4);
        $item = InboxItem::sole();

        Livewire::actingAs($this->coach)->test('pages::coach.inbox')
            ->assertSet("replies.{$item->id}", $item->draft_reply)
            ->set("replies.{$item->id}", 'Edited reply, take it easy this week.')
            ->call('approve', $item->id)
            ->assertHasNoErrors();

        $this->assertSame('done', $item->fresh()->status);
        $message = Message::sole();
        $this->assertSame('Edited reply, take it easy this week.', $message->body);
        $this->assertSame($this->coach->id, $message->sender_id);
        $this->assertSame($this->client->id, $message->client_id);
    }

    public function test_an_empty_reply_cannot_be_approved(): void
    {
        $this->submitCheckIn(energy: 2, stress: 4);
        $item = InboxItem::sole();

        Livewire::actingAs($this->coach)->test('pages::coach.inbox')
            ->set("replies.{$item->id}", '  ')
            ->call('approve', $item->id)
            ->assertHasErrors("replies.{$item->id}");

        $this->assertSame(0, Message::count());
    }

    public function test_applying_a_suggestion_changes_the_next_workout(): void
    {
        $this->submitCheckIn(energy: 2, stress: 4);
        $item = InboxItem::sole();

        Livewire::actingAs($this->coach)->test('pages::coach.inbox')->call('applySuggestion', $item->id);

        $this->assertSame(3, $this->next->items()->first()->sets);
        $this->assertTrue($item->fresh()->suggestion['applied']);
    }

    public function test_snoozed_items_leave_the_queue_until_their_time(): void
    {
        $this->submitCheckIn(energy: 4, stress: 2);
        $item = InboxItem::sole();

        Livewire::actingAs($this->coach)->test('pages::coach.inbox')->call('snooze', $item->id);

        $this->assertSame(0, $this->coach->inboxItems()->actionable()->count());
        $this->travel(2)->days();
        $this->assertSame(1, $this->coach->inboxItems()->actionable()->count());
    }

    public function test_coaches_cannot_act_on_another_coaches_items(): void
    {
        $this->submitCheckIn(energy: 2, stress: 4);
        $item = InboxItem::sole();

        Livewire::actingAs(User::factory()->create())->test('pages::coach.inbox')
            ->call('approve', $item->id)
            ->assertForbidden();

        $this->assertSame('open', $item->fresh()->status);
    }

    public function test_claude_drafts_when_a_key_is_configured_and_bad_suggestions_are_dropped(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
            'summary' => 'Tired week; sleep down.',
            'reply' => 'Hey Maya, thanks for the honest check-in.',
            'suggestion' => ['workout_exercise_id' => 999999, 'field' => 'sets', 'to' => '3', 'reason' => 'x'],
        ])]]])]);

        $this->submitCheckIn(energy: 2, stress: 4);

        $item = InboxItem::sole();
        $this->assertSame('ai', $item->drafted_by);
        $this->assertSame('Tired week; sleep down.', $item->summary);
        $this->assertNull($item->suggestion, 'A suggestion for an exercise outside the next workout must be dropped.');

        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'test-key')
            && str_contains($request['messages'][0]['content'], 'energy 2/5'));
    }

    public function test_a_failed_ai_call_falls_back_to_rules(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response('overloaded', 529)]);

        $this->submitCheckIn(energy: 2, stress: 4);

        $this->assertSame('rules', InboxItem::sole()->drafted_by);
    }

    public function test_completing_a_workout_raises_feedback_and_personal_records(): void
    {
        $squat = $this->next->items()->first();
        $earlier = Workout::factory()->forClient($this->client, today()->subWeek())->create(['completed_at' => now()->subWeek()]);
        $earlier->items()->create(['exercise_id' => $squat->exercise_id, 'sets' => 1, 'logged_sets' => [['reps' => '5', 'load' => '70 kg', 'done' => true]]]);

        Livewire::actingAs($this->client)->test('pages::training.workout', ['workout' => $this->next])
            ->set("logs.{$squat->id}.0.reps", '5')
            ->set("logs.{$squat->id}.0.load", '77.5 kg')
            ->set("logs.{$squat->id}.0.done", true)
            ->set('feedback', 'Knee felt a bit sore on the last set')
            ->call('complete');

        $types = InboxItem::pluck('type')->sort()->values()->all();
        $this->assertSame(['feedback', 'pr'], $types);
        $this->assertStringContainsString('77.5 kg, up from 70 kg', InboxItem::firstWhere('type', 'pr')->summary);
        $this->assertStringContainsString('pain', InboxItem::firstWhere('type', 'feedback')->draft_reply);
    }

    public function test_a_first_ever_lift_is_not_a_record(): void
    {
        $squat = $this->next->items()->first();
        $squat->update(['logged_sets' => [['reps' => '5', 'load' => '80 kg', 'done' => true]]]);

        $this->assertNull(app(InboxFeed::class)->newRecord($squat, $this->client));
    }

    public function test_missed_sessions_are_flagged_once(): void
    {
        Workout::factory()->forClient($this->client, today()->subDays(2))->create();
        Workout::factory()->forClient($this->client, today()->subDays(4))->create();

        $this->artisan('inbox:scan-missed')->assertSuccessful();
        $this->artisan('inbox:scan-missed')->assertSuccessful();

        $this->assertSame(1, InboxItem::where('type', 'missed')->count());
    }

    public function test_client_messages_collect_into_one_item_and_a_coach_reply_clears_it(): void
    {
        foreach (['Can I swap Thursday?', 'Also my gym is closed Friday'] as $text) {
            Livewire::actingAs($this->client)->test('pages::chat.thread', ['client' => $this->client])->set('body', $text)->call('send')->assertHasNoErrors();
        }

        $item = InboxItem::sole();
        $this->assertSame('message', $item->type);
        $this->assertSame('Also my gym is closed Friday', $item->headline);

        Livewire::actingAs($this->coach)->test('pages::chat.thread', ['client' => $this->client])
            ->set('body', 'Sure, Thursday moves to Saturday.')
            ->call('send');

        $this->assertSame('done', $item->fresh()->status);
        $this->assertSame(3, Message::count());
    }

    public function test_form_check_videos_reach_the_coach_and_stay_private(): void
    {
        Storage::fake('local');
        $squat = $this->next->items()->first();

        Livewire::actingAs($this->client)->test('pages::training.workout', ['workout' => $this->next])
            ->set("videos.{$squat->id}", UploadedFile::fake()->create('squat.mp4', 2000, 'video/mp4'))
            ->set("videoNotes.{$squat->id}", 'Knees caving?')
            ->call('sendFormCheck', $squat->id)
            ->assertHasNoErrors();

        $item = InboxItem::sole();
        $this->assertSame('form_check', $item->type);
        $formCheck = $item->subject;

        $this->actingAs($this->coach)->get(route('media.form-check', $formCheck))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('media.form-check', $formCheck))->assertForbidden();
    }

    public function test_focus_mode_walks_through_items_to_inbox_clear(): void
    {
        $this->submitCheckIn(energy: 4, stress: 2);
        $item = InboxItem::sole();

        Livewire::actingAs($this->coach)->test('pages::coach.inbox-focus')
            ->assertSee('Maya Chen')
            ->call('approve', $item->id)
            ->assertSet('cleared', 1)
            ->assertSee('Inbox clear');
    }
}
