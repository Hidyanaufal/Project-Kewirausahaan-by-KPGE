<?php

namespace App\Mail;

use App\Models\Accounting\Bill;
use App\Support\MailCompanyBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BillApprovedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Bill $bill
    ) {}

    /**
     * URL to view the bill in the company panel (after login).
     */
    public function viewUrl(): string
    {
        return url('/company/' . $this->bill->company_id . '/purchases/bills/' . $this->bill->id);
    }

    public function envelope(): Envelope
    {
        $companyName = MailCompanyBranding::resolveCompanyName($this->bill->company_id);

        return new Envelope(
            from: MailCompanyBranding::companyFromAddress($companyName),
            subject: MailCompanyBranding::prefixSubject(
                'Bill disetujui – Nomor ' . ($this->bill->bill_number ?? 'Draft'),
                $companyName
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.bill-approved',
            with: ['viewUrl' => $this->viewUrl()],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
