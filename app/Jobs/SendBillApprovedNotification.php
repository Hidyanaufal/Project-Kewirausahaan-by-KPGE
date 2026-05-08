<?php

namespace App\Jobs;

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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
    }
}
