<?php

namespace App\Mail;

use App\Models\Accounting\Invoice;
use App\Models\User;
use App\Support\MailCompanyBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceSubmissionNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public ?User $requestor = null,
    ) {}

    public function viewUrl(): string
    {
        return url('/company/' . $this->invoice->company_id . '/sales/invoices/' . $this->invoice->id);
    }

    public function envelope(): Envelope
    {
        $companyName = MailCompanyBranding::resolveCompanyName($this->invoice->company_id);

        return new Envelope(
            from: MailCompanyBranding::companyFromAddress($companyName),
            subject: MailCompanyBranding::prefixSubject('Ada pengajuan invoice baru', $companyName),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-submission',
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
