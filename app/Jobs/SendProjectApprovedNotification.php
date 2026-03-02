<?php

namespace App\Jobs;

use App\Mail\ProjectApprovedNotification;
use App\Models\Addons\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendProjectApprovedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $projectId
    ) {}

    public function handle(): void
    {
        $project = Project::find($this->projectId);
        if (! $project) {
            return;
        }

        $recipient = $project->requestor ?? $project->pic;
        if ($recipient && $recipient->email) {
            Mail::to($recipient->email)->send(new ProjectApprovedNotification($project));
        }
    }
}
