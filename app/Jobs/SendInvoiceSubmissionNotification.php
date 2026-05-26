<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsNotificationEmail;
use App\Mail\InvoiceSubmissionNotification;
use App\Models\Accounting\Invoice;
use App\Models\User;
use App\Services\NotificationRecipientService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendInvoiceSubmissionNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogsNotificationEmail, Queueable, SerializesModels;

    public function __construct(
        public int $invoiceId
    ) {}

    public function handle(NotificationRecipientService $recipientService): void
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

        $admins = $recipientService->getAdminUsersFor($invoice->company_id);

        foreach ($admins as $admin) {
            if ($admin->email) {
                Mail::to($admin->email)->send(new InvoiceSubmissionNotification($invoice, $requestor));

                $this->logNotificationEmailSent(
                    notificationType: 'submission',
                    documentType: 'invoice',
                    documentId: $invoice->id,
                    documentNumber: $invoice->invoice_number,
                    companyId: $invoice->company_id,
                    projectId: $invoice->project_id,
                    recipientUserId: $admin->id,
                    recipientEmail: $admin->email,
                    requestorId: $requestor?->id,
                    requestorEmail: $requestor?->email,
                );
            }
        }
    }
}
