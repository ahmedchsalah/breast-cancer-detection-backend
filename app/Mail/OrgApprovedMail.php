<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrgApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $managerName;
    public string $orgName;
    public string $orgType;
    public string $frontendUrl;

    public function __construct(User $manager, Organization $organization)
    {
        $this->managerName = $manager->name;
        $this->orgName     = $organization->name;
        $this->orgType     = $organization->type;
        $this->frontendUrl = rtrim(config('app.frontend_url', 'https://brecai-fed-react.vercel.app'), '/');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🎉 Your Organization Has Been Approved — BRECAI-FED',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.org-approved',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
