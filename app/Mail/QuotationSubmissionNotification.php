<?php

namespace App\Mail;

use App\Models\Accounting\Estimate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuotationSubmissionNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Estimate $estimate
    ) {}

    public function viewUrl(): string
    {
        return url('/company/' . $this->estimate->company_id . '/sales/estimates/' . $this->estimate->id);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ada pengajuan quotation baru',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quotation-submission',
            with: ['viewUrl' => $this->viewUrl()],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
