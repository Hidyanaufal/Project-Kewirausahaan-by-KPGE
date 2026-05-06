<?php

namespace App\Jobs;

use App\Mail\QuotationSubmissionNotification;
use App\Models\Accounting\Estimate;
use App\Models\User;
use App\Services\NotificationRecipientService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendQuotationSubmissionNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $estimateId
    ) {}

    public function handle(NotificationRecipientService $recipientService): void
    {
        $estimate = Estimate::find($this->estimateId);
        if (! $estimate) {
            return;
        }

        $requestor = null;
        if ($estimate->created_by) {
            $requestor = User::find($estimate->created_by);
        } elseif ($estimate->updated_by) {
            $requestor = User::find($estimate->updated_by);
        }

        $admins = $recipientService->getAdminUsersFor($estimate->company_id);

        foreach ($admins as $admin) {
            if ($admin->email) {
                Mail::to($admin->email)->send(new QuotationSubmissionNotification($estimate, $requestor));
            }
        }
    }
}
