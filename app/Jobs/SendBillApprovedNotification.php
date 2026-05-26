<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsNotificationEmail;
use App\Mail\BillApprovedNotification;
use App\Models\Accounting\Bill;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendBillApprovedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogsNotificationEmail, Queueable, SerializesModels;

    public function __construct(
        public int $billId
    ) {}

    public function handle(): void
    {
        $bill = Bill::find($this->billId);
        if (! $bill) {
            return;
        }

        $requestor = null;
        if ($bill->created_by) {
            $requestor = User::find($bill->created_by);
        } elseif ($bill->updated_by) {
            $requestor = User::find($bill->updated_by);
        }

        if (! $requestor || ! $requestor->email) {
            return;
        }

        Mail::to($requestor->email)->send(new BillApprovedNotification($bill));

        $this->logNotificationEmailSent(
            notificationType: 'approved',
            documentType: 'bill',
            documentId: $bill->id,
            documentNumber: $bill->bill_number,
            companyId: $bill->company_id,
            projectId: $bill->project_id,
            recipientUserId: $requestor->id,
            recipientEmail: $requestor->email,
            requestorId: $requestor->id,
            requestorEmail: $requestor->email,
        );
    }
}
