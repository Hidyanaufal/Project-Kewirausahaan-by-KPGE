<?php

namespace App\Mail;

use App\Models\Addons\Project;
use App\Models\User;
use App\Support\MailCompanyBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectApprovedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Project $project,
        public ?User $recipient = null,
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
        $companyName = MailCompanyBranding::resolveCompanyName($this->project->company_id);

        return new Envelope(
            from: MailCompanyBranding::companyFromAddress($companyName),
            subject: MailCompanyBranding::prefixSubject(
                'Project disetujui – Nomor ' . $this->project->number,
                $companyName
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.project-approved',
            with: [
                'viewUrl' => $this->viewUrl(),
                'recipientName' => $this->recipient?->name,
                'approvalStatus' => $this->project->status?->getLabel() ?? 'Approved',
                'approvalDate' => $this->project->updated_at,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
