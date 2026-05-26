<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsNotificationEmail;
use App\Mail\ProjectSubmissionNotification;
use App\Models\Addons\Project;
use App\Services\NotificationRecipientService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendProjectSubmissionNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogsNotificationEmail, Queueable, SerializesModels;

    public function __construct(
        public int $projectId
    ) {}

    public function handle(NotificationRecipientService $recipientService): void
    {
        $project = Project::find($this->projectId);
        if (! $project || ! $project->requestor) {
            return;
        }

        $admins = $recipientService->getAdminUsersFor($project->company_id);

        if ($admins->isEmpty()) {
            Log::warning('SendProjectSubmissionNotification: tidak ada penerima (admin peran + super admin + owner company).', [
                'project_id' => $project->id,
                'company_id' => $project->company_id,
                'requestor_id' => $project->requestor->id,
                'requestor_email' => $project->requestor->email,
                'admin_count' => 0,
            ]);

            return;
        }

        Log::info('SendProjectSubmissionNotification: admin penerima ditemukan.', [
            'project_id' => $project->id,
            'company_id' => $project->company_id,
            'requestor_id' => $project->requestor->id,
            'requestor_email' => $project->requestor->email,
            'admin_count' => $admins->count(),
            'admin_user_ids' => $admins->pluck('id')->all(),
            'admin_emails' => $admins->pluck('email')->filter()->values()->all(),
        ]);

        foreach ($admins as $admin) {
            if ($admin->email) {
                Mail::to($admin->email)->send(new ProjectSubmissionNotification($project, $project->requestor));

                $this->logNotificationEmailSent(
                    notificationType: 'submission',
                    documentType: 'project',
                    documentId: $project->id,
                    documentNumber: $project->number,
                    companyId: $project->company_id,
                    projectId: $project->id,
                    recipientUserId: $admin->id,
                    recipientEmail: $admin->email,
                    requestorId: $project->requestor->id,
                    requestorEmail: $project->requestor->email,
                );
            }
        }
    }
}
