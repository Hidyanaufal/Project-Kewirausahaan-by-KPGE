<?php

namespace App\Mail;

use App\Models\CompanyInvitation;
use App\Support\MailCompanyBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CompanyEmployeeInvitationNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CompanyInvitation $invitation,
    ) {}

    public function loginUrl(): string
    {
        return url('/login');
    }

    public function envelope(): Envelope
    {
        $companyName = $this->invitation->company?->name
            ?: MailCompanyBranding::resolveCompanyName($this->invitation->company_id);

        return new Envelope(
            from: MailCompanyBranding::companyFromAddress($companyName),
            subject: MailCompanyBranding::prefixSubject('Undangan bergabung ke company', $companyName),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.company-employee-invitation',
            with: [
                'companyName' => $this->invitation->company?->name,
                'role' => $this->invitation->role,
                'loginUrl' => $this->loginUrl(),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}

