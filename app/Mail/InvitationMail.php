<?php

namespace App\Mail;

use App\Models\Invitation;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $recipientEmail;
    public string $orgName;
    public string $orgType;
    public string $role;
    public string $registerUrl;
    public string $expiresAt;

    public function __construct(Invitation $invitation, Organization $organization)
    {
        $this->recipientEmail = $invitation->email;
        $this->orgName        = $organization->name;
        $this->orgType        = $organization->type;
        $this->role           = $invitation->role;
        $this->expiresAt      = $invitation->expires_at->format('d M Y H:i');

        $frontendUrl = rtrim(config('app.frontend_url', 'https://brecai-fed-react.vercel.app'), '/');

        if ($invitation->role === 'instructor') {
            $this->registerUrl = $frontendUrl . '/auth/invite/' . $invitation->token;
        } else {
            $this->registerUrl = $frontendUrl . '/auth/signup?token=' . $invitation->token;
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "🎉 You're invited to join {$this->orgName} on BRECAI-FED",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invitation');
    }

    public function attachments(): array
    {
        return [];
    }
}
