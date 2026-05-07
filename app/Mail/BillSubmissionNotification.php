<?php

namespace App\Mail;

use App\Models\Accounting\Bill;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BillSubmissionNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Bill $bill,
        public ?User $requestor = null,
    ) {}

    public function viewUrl(): string
    {
        return url('/company/' . $this->bill->company_id . '/purchases/bills/' . $this->bill->id);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ada pengajuan bill baru',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.bill-submission',
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
