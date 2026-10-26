<?php

namespace Tests\Feature\Coaching;

use App\Mail\UnreadMessages;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat;
use App\Services\TeamManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected User $coach;

    protected User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coach = User::factory()->create(['name' => 'Alex Rivera']);
        $this->client = User::factory()->clientOf($this->coach)->create(['name' => 'Maya Chen', 'invitation_accepted_at' => now()]);
    }

    /** Put the coach and client in a gym; returns the owner. */
    protected function gymOwner(): User
    {
        $owner = User::factory()->create(['name' => 'Gym Owner']);
        $this->coach->team?->delete();
        $this->coach->forceFill(['team_id' => $owner->team_id, 'team_role' => 'coach'])->save();
        $this->client->forceFill(['team_id' => $owner->team_id])->save();
        $this->coach->refresh();
        $this->client->refresh();

        return $owner->fresh();
    }

    protected function thread(User $as)
    {
        return Livewire::actingAs($as)->test('pages::chat.thread', ['client' => $this->client]);
    }

    public function test_coach_and_client_can_chat_and_see_read_receipts(): void
    {
        $this->thread($this->client)->set('body', 'Can I swap Thursday?')->call('send')->assertHasNoErrors();

        $this->actingAs($this->coach)->get(route('coach.messages'))->assertOk()->assertSee('Maya Chen')->assertSee('Can I swap Thursday?');

        // Opening the thread marks it read; the client sees "Seen".
        $this->thread($this->coach)->assertSee('Can I swap Thursday?');
        $this->assertNotNull(Message::sole()->read_at);
        $this->thread($this->client)->assertSee('Seen');

        $this->thread($this->coach)->set('body', 'Sure, Saturday works.')->call('send');
        $this->assertSame(2, Message::count());
        $this->assertSame('Alex Rivera', Message::latest('id')->first()->sender->name);
    }

    public function test_the_poll_skips_rendering_until_something_changes(): void
    {
        $page = $this->thread($this->coach);
        $page->call('checkForNew')->assertDontSee('New from Maya');

        app(Chat::class)->send($this->client, $this->client, 'New from Maya');

        $page->call('checkForNew')->assertSee('New from Maya');
        $this->assertNotNull(Message::sole()->read_at, 'Seen while the thread is open.');
    }

    public function test_unread_counts_and_badges(): void
    {
        $chat = app(Chat::class);
        $chat->send($this->client, $this->client, 'One');
        $chat->send($this->client, $this->client, 'Two');

        $this->assertSame(2, $chat->unreadCount($this->coach));
        $this->assertSame(0, $chat->unreadCount($this->client));

        // Replying (here from the inbox) counts as reading.
        $chat->send($this->coach, $this->client, 'Got it');
        $this->assertSame(0, $chat->unreadCount($this->coach));
        $this->assertSame(1, $chat->unreadCount($this->client));
    }

    public function test_photos_can_be_sent_and_stay_private(): void
    {
        Storage::fake('local');

        $this->thread($this->client)
            ->set('photos', [UploadedFile::fake()->image('meal.jpg')])
            ->call('send')
            ->assertHasNoErrors();

        $message = Message::sole();
        $this->assertCount(1, $message->photos);
        $this->assertSame('Sent a photo', $message->preview());
        $this->assertSame('Sent a photo', InboxItem::sole()->headline);

        $this->actingAs($this->coach)->get(route('media.message-photo', [$message, 0]))->assertOk();
        $this->actingAs($this->client)->get(route('media.message-photo', [$message, 0]))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('media.message-photo', [$message, 0]))->assertForbidden();
        $this->actingAs(User::factory()->clientOf($this->coach)->create())->get(route('media.message-photo', [$message, 0]))->assertForbidden();
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $this->thread($this->client)->set('body', '   ')->call('send')->assertHasErrors('body');
        $this->assertSame(0, Message::count());
    }

    public function test_other_coaches_and_clients_cannot_open_the_thread(): void
    {
        $stranger = User::factory()->create();
        $otherClient = User::factory()->clientOf($this->coach)->create();

        Livewire::actingAs($stranger)->test('pages::chat.thread', ['client' => $this->client])->assertForbidden();
        Livewire::actingAs($otherClient)->test('pages::chat.thread', ['client' => $this->client])->assertForbidden();
        $this->actingAs($stranger)->get(route('coach.messages', ['client' => $this->client->id]))->assertForbidden();
    }

    public function test_a_reassigned_client_disappears_from_the_old_coach_mid_conversation(): void
    {
        $owner = $this->gymOwner();
        $this->withoutExceptionHandling();
        $page = $this->thread($this->coach);

        app(TeamManager::class)->reassign($this->client->fresh(), $owner->fresh());

        try {
            $page->set('body', 'Still here?')->call('send');
            $this->fail('The old coach could still send.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, Message::count());
    }

    public function test_a_coach_reply_clears_the_message_item_even_from_a_teammate(): void
    {
        $owner = $this->gymOwner();

        $this->thread($this->client)->set('body', 'Hi')->call('send');
        $item = InboxItem::sole();
        $this->assertSame($this->coach->id, $item->coach_id);

        Livewire::actingAs($owner->fresh())->test('pages::chat.thread', ['client' => $this->client->fresh()])->set('body', 'Owner here, Alex is away.')->call('send');

        $this->assertSame('done', $item->fresh()->status);
    }

    public function test_conversation_list_orders_by_latest_message_and_searches(): void
    {
        $quiet = User::factory()->clientOf($this->coach)->create(['name' => 'Quinn Quiet']);
        $chatty = User::factory()->clientOf($this->coach)->create(['name' => 'Chris Chatty']);
        app(Chat::class)->send($this->client, $this->client, 'older');
        app(Chat::class)->send($chatty, $chatty, 'newest');

        Livewire::actingAs($this->coach)->test('pages::coach.messages')
            ->assertSeeInOrder(['Chris Chatty', 'Maya Chen', 'Quinn Quiet'])
            ->set('search', 'quinn')
            ->assertSee('Quinn Quiet')
            ->assertDontSee('Chris Chatty');
    }

    public function test_old_conversation_links_redirect(): void
    {
        $this->actingAs($this->coach)->get('/coach/clients/'.$this->client->id.'/messages')
            ->assertRedirect(route('coach.messages', ['client' => $this->client->id]));
    }

    public function test_unread_messages_are_emailed_once_after_a_few_minutes(): void
    {
        Mail::fake();
        $chat = app(Chat::class);
        $chat->send($this->client, $this->client, 'Are we still on for Thursday?');

        $this->artisan('messages:notify-unread')->assertSuccessful();
        Mail::assertNothingQueued();

        $this->travel(11)->minutes();
        $this->artisan('messages:notify-unread');
        Mail::assertQueued(UnreadMessages::class, fn ($mail) => $mail->hasTo($this->coach->email) && $mail->count === 1);

        // A follow-up while the first email is unanswered doesn't send another.
        $chat->send($this->client, $this->client, 'Hello?');
        $this->travel(11)->minutes();
        $this->artisan('messages:notify-unread');
        Mail::assertQueuedCount(1);

        // Once read, nothing more goes out.
        $this->travel(2)->hours();
        $this->artisan('messages:notify-unread');
        Mail::assertQueuedCount(1);
    }

    public function test_clients_who_have_not_joined_are_not_emailed(): void
    {
        Mail::fake();
        $pending = User::factory()->clientOf($this->coach)->create(['invitation_accepted_at' => null]);
        app(Chat::class)->send($this->coach, $pending, 'Welcome!');

        $this->travel(11)->minutes();
        $this->artisan('messages:notify-unread');

        Mail::assertNothingQueued();
        $this->assertNotNull(Message::sole()->notified_at);
    }

    public function test_the_email_renders_in_the_recipients_language(): void
    {
        $this->client->update(['locale' => 'fr']);
        $message = app(Chat::class)->send($this->coach, $this->client, 'Bonne semaine !');

        $html = (new UnreadMessages($this->client, $this->client, 1, $message))->locale('fr')->render();

        $this->assertStringContainsString('Bonne semaine !', $html);
        $this->assertStringContainsString('Répondre', $html);
    }
}
