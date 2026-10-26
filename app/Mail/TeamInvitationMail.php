<?php

namespace App\Mail;

use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invites a coach to join a team on Spotter.
 */
class TeamInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public TeamInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Join :team on Spotter', ['team' => $this->invitation->team->name]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.team-invitation', with: [
            'team' => $this->invitation->team,
            'inviter' => $this->invitation->inviter,
            'url' => route('team-invitation.accept', $this->invitation->token),
            'expires' => $this->invitation->expires_at,
        ]);
    }
}
