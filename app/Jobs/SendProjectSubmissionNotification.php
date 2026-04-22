<?php

namespace App\Jobs;

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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
            ]);

            return;
        }

        foreach ($admins as $admin) {
            if ($admin->email) {
                Mail::to($admin->email)->send(new ProjectSubmissionNotification($project, $project->requestor));
            }
        }
    }
}
