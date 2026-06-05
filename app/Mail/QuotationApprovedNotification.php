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

class QuotationApprovedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Estimate $estimate,
        public ?User $recipient = null,
    ) {}

    /**
     * URL to view the estimate in the company panel (after login).
     */
    public function viewUrl(): string
    {
        return url('/company/' . $this->estimate->company_id . '/sales/estimates/' . $this->estimate->id);
    }

    public function envelope(): Envelope
    {
        $companyName = MailCompanyBranding::resolveCompanyName($this->estimate->company_id);

        return new Envelope(
            from: MailCompanyBranding::companyFromAddress($companyName),
            subject: MailCompanyBranding::prefixSubject(
                'Quotation disetujui – Nomor ' . ($this->estimate->estimate_number ?? 'Draft'),
                $companyName
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quotation-approved',
            with: [
                'viewUrl' => $this->viewUrl(),
                'recipientName' => $this->recipient?->name,
                'approvalStatus' => 'Disetujui',
                'approvalDate' => $this->estimate->approved_at,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
