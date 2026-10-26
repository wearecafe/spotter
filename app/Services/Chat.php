<?php

namespace App\Services;

use App\Mail\UnreadMessages;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The conversation between a client and their coaching team. One thread per client: it follows
 * the client if they move to another coach in the gym. There are no websockets; pages poll.
 */
class Chat
{
    public const MAX_PHOTOS = 4;

    /** Minutes a message waits unread before the recipient gets an email. */
    public const NOTIFY_AFTER = 10;

    /** At most one email per conversation in this many minutes while earlier ones go unread. */
    public const NOTIFY_QUIET = 60;

    public function __construct(protected InboxFeed $feed) {}

    /**
     * @param  array<int, UploadedFile>  $photos
     */
    public function send(User $sender, User $client, string $body, array $photos = []): Message
    {
        $paths = collect($photos)
            ->map(fn (UploadedFile $photo) => $photo->store('messages/'.$client->id, config('filesystems.media_disk')))
            ->values()->all();

        $message = DB::transaction(function () use ($sender, $client, $body, $paths) {
            $message = Message::create([
                'coach_id' => $sender->id === $client->id ? $client->coach_id : $sender->id,
                'client_id' => $client->id,
                'sender_id' => $sender->id,
                'body' => trim($body),
                'photos' => $paths ?: null,
            ]);

            // Replying means you've seen what came before.
            $this->markRead($sender, $client);

            return $message;
        });

        if ($sender->id === $client->id) {
            $this->feed->messageReceived($message);
        }

        return $message;
    }

    /**
     * Mark the other side's messages in this thread as read by $reader.
     */
    public function markRead(User $reader, User $client): int
    {
        $query = Message::query()->thread($client)->whereNull('read_at');

        $reader->id === $client->id
            ? $query->where('sender_id', '!=', $client->id)
            : $query->where('sender_id', $client->id);

        return $query->update(['read_at' => now()]);
    }

    /**
     * A coach replying in the chat answers the client's open message item, whoever it's assigned to.
     */
    public function resolveMessageItems(User $client): void
    {
        InboxItem::query()
            ->where('client_id', $client->id)
            ->where('type', 'message')
            ->where('status', '!=', 'done')
            ->update(['status' => 'done', 'resolved_at' => now()]);
    }

    /**
     * Messages waiting for $user: from their client(s) if they coach, from their coaching team if a client.
     */
    public function unreadCount(User $user): int
    {
        if ($user->isClient()) {
            return Message::query()->thread($user)->where('sender_id', '!=', $user->id)->whereNull('read_at')->count();
        }

        return Message::query()
            ->whereIn('client_id', $user->clients()->select('id'))
            ->whereColumn('sender_id', 'client_id')
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Email people about messages they haven't read after a few minutes. Run every few minutes.
     * One email per conversation, and no repeat while an earlier email is still unanswered.
     */
    public function notifyUnread(): int
    {
        $sent = 0;

        Message::query()
            ->whereNull('read_at')
            ->whereNull('notified_at')
            ->where('created_at', '<=', now()->subMinutes(self::NOTIFY_AFTER))
            ->where('created_at', '>=', now()->subDays(2))
            ->with(['client.coach', 'sender:id,name'])
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Message $m) => $m->client_id.'-'.($m->fromClient() ? 'coach' : 'client'))
            ->each(function ($messages) use (&$sent) {
                /** @var Message $latest */
                $latest = $messages->last();
                $recipient = $latest->recipient();

                $recentlyEmailed = Message::query()->thread($latest->client)
                    ->where('sender_id', $latest->fromClient() ? '=' : '!=', $latest->client_id)
                    ->whereNull('read_at')
                    ->where('notified_at', '>=', now()->subMinutes(self::NOTIFY_QUIET))
                    ->exists();

                $canReceive = $recipient && $recipient->email
                    && ($recipient->isCoach() || $recipient->hasAcceptedInvitation());

                if ($canReceive && ! $recentlyEmailed) {
                    Mail::to($recipient)->queue(new UnreadMessages($recipient, $latest->client, $messages->count(), $latest));
                    $sent++;
                }

                Message::query()->whereIn('id', $messages->pluck('id'))->update(['notified_at' => now()]);
            });

        return $sent;
    }
}
