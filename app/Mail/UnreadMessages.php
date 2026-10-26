<?php

namespace App\Mail;

use App\Models\Message;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "You have a message" for someone who hasn't opened the chat. Sent in the recipient's language.
 */
class UnreadMessages extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $recipient, public User $client, public int $count, public Message $latest) {}

    public function envelope(): Envelope
    {
        $sender = $this->latest->sender?->name ?? __('Your coach');

        return new Envelope(
            subject: trans_choice('New message from :name|:count new messages from :name', $this->count, ['name' => $sender, 'count' => $this->count]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.unread-messages', with: [
            'firstName' => str($this->recipient->name)->before(' '),
            'sender' => $this->latest->sender?->name,
            'preview' => $this->latest->preview(280),
            'count' => $this->count,
            'url' => $this->recipient->isClient()
                ? route('training.messages')
                : route('coach.messages', ['client' => $this->client->id]),
        ]);
    }
}
