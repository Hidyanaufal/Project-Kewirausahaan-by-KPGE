<?php

namespace App\Jobs;

use App\Mail\QuotationApprovedNotification;
use App\Models\Accounting\Estimate;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendQuotationApprovedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $estimateId
    ) {}

    public function handle(): void
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

        if (! $requestor || ! $requestor->email) {
            return;
        }

        Mail::to($requestor->email)->send(new QuotationApprovedNotification($estimate));
    }
}
