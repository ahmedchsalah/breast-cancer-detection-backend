<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $token;
    public string $userName;
    public string $userRole;

    public function __construct(string $token, User $user)
    {
        $this->token    = $token;
        $this->userName = $user->name;
        $this->userRole = $user->getRoleNames()->first() ?? 'user';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Verification Code — Federated Medical AI',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
