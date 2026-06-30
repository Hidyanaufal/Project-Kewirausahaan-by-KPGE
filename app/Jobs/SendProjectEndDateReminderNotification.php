<?php

namespace App\Jobs;

use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Addons\ProjectStatus;
use App\Jobs\Concerns\LogsNotificationEmail;
use App\Mail\ProjectEndDateReminderNotification;
use App\Models\Addons\Project;
use App\Models\User;
use App\Services\NotificationRecipientService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendProjectEndDateReminderNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogsNotificationEmail, Queueable, SerializesModels;

    public function __construct(
        public int $projectId
    ) {}

    public function handle(NotificationRecipientService $recipientService): void
    {
        $project = Project::find($this->projectId);

        if (! $project || $project->status !== ProjectStatus::Approved || $project->end_date_reminder_sent_at) {
            return;
        }

        $invoiceCount = $project->invoices()
            ->whereNotIn('status', [InvoiceStatus::Cancelled])
            ->count();

        $recipients = $this->resolveRecipients($project, $recipientService);

        if ($recipients->isEmpty()) {
            $this->logNotificationEmailSkipped(
                notificationType: 'end_date_reminder',
                documentType: 'project',
                documentId: $project->id,
                reason: 'recipients_missing',
                extraContext: [
                    'document_number' => $project->number,
                    'company_id' => $project->company_id,
                    'project_id' => $project->id,
                ],
            );

            return;
        }

        foreach ($recipients as $recipient) {
            if (! $recipient->email) {
                continue;
            }

            try {
                Mail::to($recipient->email)->send(
                    new ProjectEndDateReminderNotification($project, $recipient, $invoiceCount)
                );
            } catch (Throwable $exception) {
                $this->logNotificationEmailFailed(
                    notificationType: 'end_date_reminder',
                    documentType: 'project',
                    documentId: $project->id,
                    recipientEmail: $recipient->email,
                    errorMessage: $exception->getMessage(),
                    extraContext: [
                        'document_number' => $project->number,
                        'company_id' => $project->company_id,
                        'project_id' => $project->id,
                        'recipient_user_id' => $recipient->id,
                    ],
                );

                throw $exception;
            }

            $this->logNotificationEmailSent(
                notificationType: 'end_date_reminder',
                documentType: 'project',
                documentId: $project->id,
                documentNumber: $project->number,
                companyId: $project->company_id,
                projectId: $project->id,
                recipientUserId: $recipient->id,
                recipientEmail: $recipient->email,
                requestorId: $project->requestor_id,
                requestorEmail: $project->requestor?->email,
            );
        }

        $project->update([
            'end_date_reminder_sent_at' => company_now(),
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    protected function resolveRecipients(Project $project, NotificationRecipientService $recipientService): Collection
    {
        $recipients = collect();

        if ($project->requestor) {
            $recipients->push($project->requestor);
        }

        $admins = $recipientService->getAdminUsersFor($project->company_id);

        foreach ($admins as $admin) {
            $recipients->push($admin);
        }

        return $recipients
            ->filter(fn (User $user) => filled($user->email))
            ->unique('email')
            ->values();
    }
}
