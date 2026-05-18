<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DoctorActivatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $doctorName;
    public string $orgName;
    public string $frontendUrl;

    public function __construct(User $doctor, Organization $organization)
    {
        $this->doctorName  = $doctor->name;
        $this->orgName     = $organization->name;
        $this->frontendUrl = rtrim(config('app.frontend_url', 'https://brecai-fed-react.vercel.app'), '/');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '✅ Your Account Has Been Activated — BRECAI-FED',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.doctor-activated',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
