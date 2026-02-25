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

        foreach ($admins as $admin) {
            if ($admin->email) {
                Mail::to($admin->email)->send(new ProjectSubmissionNotification($project, $project->requestor));
            }
        }
    }
}
