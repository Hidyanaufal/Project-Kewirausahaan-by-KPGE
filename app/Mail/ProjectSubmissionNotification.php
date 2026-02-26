<?php

namespace App\Mail;

use App\Models\Addons\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectSubmissionNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Project $project,
        public User $requestor
    ) {}

    /**
     * URL to view the project in the company panel (admin can open after login).
     */
    public function viewUrl(): string
    {
        return url('/company/' . $this->project->company_id . '/addons/projects/' . $this->project->id);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Pengajuan project baru oleh ' . $this->requestor->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.project-submission',
            with: ['viewUrl' => $this->viewUrl()],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
