<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invites a client to join their coach on Spotter. Sent in the client's language.
 */
class ClientInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $client) {}

    public function envelope(): Envelope
    {
        $coach = $this->client->coach;

        return new Envelope(
            subject: $this->isSwitch()
                ? __(':coach is moving your coaching to Spotter', ['coach' => $coach->brandName()])
                : __(':coach invited you to train on Spotter', ['coach' => $coach->brandName()]),
            replyTo: [$coach->email],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.client-invitation', with: [
            'coach' => $this->client->coach,
            'isSwitch' => $this->isSwitch(),
            'url' => route('invitation.accept', $this->client->invitation_token),
            'firstName' => str($this->client->name)->before(' '),
        ]);
    }

    protected function isSwitch(): bool
    {
        return in_array($this->client->coach?->import_source, ['truecoach', 'trainerize', 'sheets'], true);
    }
}
