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
use Throwable;

class SendInvoiceApprovedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogsNotificationEmail, Queueable, SerializesModels;

    public function __construct(
        public int $invoiceId
    ) {}

    public function handle(): void
    {
        $invoice = Invoice::find($this->invoiceId);
        if (! $invoice || ! $invoice->isFinalized()) {
            return;
        }

        $requestor = $this->resolveRequestor($invoice->created_by, $invoice->updated_by);

        if (! $requestor || ! $requestor->email) {
            $this->logNotificationEmailSkipped(
                notificationType: 'approved',
                documentType: 'invoice',
                documentId: $invoice->id,
                reason: 'requestor_email_missing',
                extraContext: [
                    'document_number' => $invoice->invoice_number,
                    'company_id' => $invoice->company_id,
                    'project_id' => $invoice->project_id,
                    'requestor_id' => $requestor?->id,
                ],
            );

            return;
        }

        try {
            Mail::to($requestor->email)->send(new InvoiceApprovedNotification($invoice, $requestor));
        } catch (Throwable $exception) {
            $this->logNotificationEmailFailed(
                notificationType: 'approved',
                documentType: 'invoice',
                documentId: $invoice->id,
                recipientEmail: $requestor->email,
                errorMessage: $exception->getMessage(),
                extraContext: [
                    'document_number' => $invoice->invoice_number,
                    'company_id' => $invoice->company_id,
                    'project_id' => $invoice->project_id,
                    'recipient_user_id' => $requestor->id,
                    'requestor_id' => $requestor->id,
                ],
            );

            throw $exception;
        }

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

    protected function resolveRequestor(?int $createdBy, ?int $updatedBy): ?User
    {
        if ($createdBy) {
            return User::find($createdBy);
        }

        if ($updatedBy) {
            return User::find($updatedBy);
        }

        return null;
    }
}
