<?php

namespace App\Mail;

use App\Models\Accounting\Estimate;
use App\Models\User;
use App\Support\MailCompanyBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuotationSubmissionNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Estimate $estimate,
        public ?User $requestor = null,
    ) {}

    public function viewUrl(): string
    {
        return url('/company/' . $this->estimate->company_id . '/sales/estimates/' . $this->estimate->id);
    }

    public function envelope(): Envelope
    {
        $companyName = MailCompanyBranding::resolveCompanyName($this->estimate->company_id);

        return new Envelope(
            from: MailCompanyBranding::companyFromAddress($companyName),
            subject: MailCompanyBranding::prefixSubject('Ada pengajuan quotation baru', $companyName),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quotation-submission',
            with: [
                'viewUrl' => $this->viewUrl(),
                'requestor' => $this->requestor,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
