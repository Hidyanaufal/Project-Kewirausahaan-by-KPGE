<?php

namespace App\Mail;

use App\Models\Addons\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectApprovedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Project $project
    ) {}

    /**
     * URL to view the project in the company panel (after login).
     */
    public function viewUrl(): string
    {
        return url('/company/' . $this->project->company_id . '/addons/projects/' . $this->project->id);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Project disetujui – Nomor ' . $this->project->number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.project-approved',
            with: ['viewUrl' => $this->viewUrl()],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
