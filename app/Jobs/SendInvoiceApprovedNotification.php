<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsNotificationEmail;
use App\Mail\InvoiceApprovedNotification;
use App\Models\Accounting\Invoice;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendInvoiceApprovedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogsNotificationEmail, Queueable, SerializesModels;

    public function __construct(
        public int $invoiceId
    ) {}

    public function handle(): void
    {
        $invoice = Invoice::find($this->invoiceId);
        if (! $invoice) {
            return;
        }

        $requestor = null;
        if ($invoice->created_by) {
            $requestor = User::find($invoice->created_by);
        } elseif ($invoice->updated_by) {
            $requestor = User::find($invoice->updated_by);
        }

        if (! $requestor || ! $requestor->email) {
            return;
        }

        Mail::to($requestor->email)->send(new InvoiceApprovedNotification($invoice));

        $this->logNotificationEmailSent(
            notificationType: 'approved',
            documentType: 'invoice',
            documentId: $invoice->id,
            documentNumber: $invoice->invoice_number,
            companyId: $invoice->company_id,
            projectId: $invoice->project_id,
            recipientUserId: $requestor->id,
            recipientEmail: $requestor->email,
            requestorId: $requestor->id,
            requestorEmail: $requestor->email,
        );
    }
}
