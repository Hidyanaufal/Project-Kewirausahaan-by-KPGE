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

class ProjectEndDateReminderNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Project $project,
        public ?User $recipient = null,
        public int $invoiceCount = 0,
    ) {}

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
                'Project selesai – mohon lengkapi administrasi – ' . $this->project->number,
                $companyName
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.project-end-date-reminder',
            with: [
                'viewUrl' => $this->viewUrl(),
                'recipientName' => $this->recipient?->name,
                'endDate' => $this->project->end_date,
                'invoiceCount' => $this->invoiceCount,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
