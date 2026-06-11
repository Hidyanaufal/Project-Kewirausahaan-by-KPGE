<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsNotificationEmail;
use App\Mail\QuotationApprovedNotification;
use App\Models\Accounting\Estimate;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendQuotationApprovedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogsNotificationEmail, Queueable, SerializesModels;

    public function __construct(
        public int $estimateId
    ) {}

    public function handle(): void
    {
        $estimate = Estimate::find($this->estimateId);
        if (! $estimate || ! $estimate->wasApproved()) {
            return;
        }

        $requestor = $this->resolveRequestor($estimate->created_by, $estimate->updated_by);

        if (! $requestor || ! $requestor->email) {
            $this->logNotificationEmailSkipped(
                notificationType: 'approved',
                documentType: 'quotation',
                documentId: $estimate->id,
                reason: 'requestor_email_missing',
                extraContext: [
                    'document_number' => $estimate->estimate_number,
                    'company_id' => $estimate->company_id,
                    'project_id' => $estimate->project_id,
                    'requestor_id' => $requestor?->id,
                ],
            );

            return;
        }

        try {
            Mail::to($requestor->email)->send(new QuotationApprovedNotification($estimate, $requestor));
        } catch (Throwable $exception) {
            $this->logNotificationEmailFailed(
                notificationType: 'approved',
                documentType: 'quotation',
                documentId: $estimate->id,
                recipientEmail: $requestor->email,
                errorMessage: $exception->getMessage(),
                extraContext: [
                    'document_number' => $estimate->estimate_number,
                    'company_id' => $estimate->company_id,
                    'project_id' => $estimate->project_id,
                    'recipient_user_id' => $requestor->id,
                    'requestor_id' => $requestor->id,
                ],
            );

            throw $exception;
        }

        $this->logNotificationEmailSent(
            notificationType: 'approved',
            documentType: 'quotation',
            documentId: $estimate->id,
            documentNumber: $estimate->estimate_number,
            companyId: $estimate->company_id,
            projectId: $estimate->project_id,
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
