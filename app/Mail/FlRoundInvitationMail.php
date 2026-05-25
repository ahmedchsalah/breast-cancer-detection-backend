<?php

namespace App\Mail;

use App\Models\FlRoundInvitation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FlRoundInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly FlRoundInvitation $invitation,
        public readonly User $instructor,
        public readonly string $approveUrl,
    ) {}

    public function envelope(): Envelope
    {
        $round = $this->invitation->flRound;
        return new Envelope(
            subject: "FL Round #{$round->round_number} Invitation — BReCAI",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.fl-round-invitation');
    }

    public function attachments(): array
    {
        return [];
    }
}
