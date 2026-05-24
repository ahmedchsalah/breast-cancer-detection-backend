<?php

namespace App\Mail;

use App\Models\Report;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class ReportGeneratedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Report $report,
        public readonly User   $doctor,
        public readonly string $pdfContent,   // base64-encoded PDF or HTML content
        public readonly string $pdfFilename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Clinical Report — {$this->report->patient?->patient_identifier} | BRECAI-FED",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.report-generated',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
