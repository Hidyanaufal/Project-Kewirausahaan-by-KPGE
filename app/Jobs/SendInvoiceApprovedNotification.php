<?php

namespace App\Jobs;

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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
    }
}
