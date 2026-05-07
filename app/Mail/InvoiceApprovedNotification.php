<?php

namespace App\Mail;

use App\Models\Accounting\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceApprovedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice
    ) {}

    /**
     * URL to view the invoice in the company panel (after login).
     */
    public function viewUrl(): string
    {
        return url('/company/' . $this->invoice->company_id . '/sales/invoices/' . $this->invoice->id);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invoice disetujui – Nomor ' . ($this->invoice->invoice_number ?? 'Draft'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-approved',
            with: ['viewUrl' => $this->viewUrl()],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
