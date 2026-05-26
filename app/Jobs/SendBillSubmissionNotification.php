<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsNotificationEmail;
use App\Mail\BillSubmissionNotification;
use App\Models\Accounting\Bill;
use App\Models\User;
use App\Services\NotificationRecipientService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendBillSubmissionNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogsNotificationEmail, Queueable, SerializesModels;

    public function __construct(
        public int $billId
    ) {}

    public function handle(NotificationRecipientService $recipientService): void
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

        $admins = $recipientService->getAdminUsersFor($bill->company_id);

        foreach ($admins as $admin) {
            if ($admin->email) {
                Mail::to($admin->email)->send(new BillSubmissionNotification($bill, $requestor));

                $this->logNotificationEmailSent(
                    notificationType: 'submission',
                    documentType: 'bill',
                    documentId: $bill->id,
                    documentNumber: $bill->bill_number,
                    companyId: $bill->company_id,
                    projectId: $bill->project_id,
                    recipientUserId: $admin->id,
                    recipientEmail: $admin->email,
                    requestorId: $requestor?->id,
                    requestorEmail: $requestor?->email,
                );
            }
        }
    }
}
