<?php

namespace App\Jobs;

use App\Enums\Addons\ProjectStatus;
use App\Jobs\Concerns\LogsNotificationEmail;
use App\Mail\ProjectApprovedNotification;
use App\Models\Addons\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendProjectApprovedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogsNotificationEmail, Queueable, SerializesModels;

    public function __construct(
        public int $projectId
    ) {}

    public function handle(): void
    {
        $project = Project::find($this->projectId);
        if (! $project || $project->status !== ProjectStatus::Approved) {
            return;
        }

        $recipient = $project->requestor ?? $project->pic;

        if (! $recipient || ! $recipient->email) {
            $this->logNotificationEmailSkipped(
                notificationType: 'approved',
                documentType: 'project',
                documentId: $project->id,
                reason: 'requestor_email_missing',
                extraContext: [
                    'document_number' => $project->number,
                    'company_id' => $project->company_id,
                    'project_id' => $project->id,
                    'requestor_id' => $recipient?->id,
                ],
            );

            return;
        }

        try {
            Mail::to($recipient->email)->send(new ProjectApprovedNotification($project, $recipient));
        } catch (Throwable $exception) {
            $this->logNotificationEmailFailed(
                notificationType: 'approved',
                documentType: 'project',
                documentId: $project->id,
                recipientEmail: $recipient->email,
                errorMessage: $exception->getMessage(),
                extraContext: [
                    'document_number' => $project->number,
                    'company_id' => $project->company_id,
                    'project_id' => $project->id,
                    'recipient_user_id' => $recipient->id,
                    'requestor_id' => $recipient->id,
                ],
            );

            throw $exception;
        }

        $this->logNotificationEmailSent(
            notificationType: 'approved',
            documentType: 'project',
            documentId: $project->id,
            documentNumber: $project->number,
            companyId: $project->company_id,
            projectId: $project->id,
            recipientUserId: $recipient->id,
            recipientEmail: $recipient->email,
            requestorId: $recipient->id,
            requestorEmail: $recipient->email,
        );
    }
}
