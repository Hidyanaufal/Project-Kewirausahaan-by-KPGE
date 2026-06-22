<?php

namespace App\Jobs;

use App\Enums\Addons\ProjectStatus;
use App\Models\Addons\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendProjectEndDateRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Project::query()
            ->where('status', ProjectStatus::Approved)
            ->whereNotNull('end_date')
            ->whereNull('end_date_reminder_sent_at')
            ->whereDate('end_date', company_today()->subDay())
            ->each(fn (Project $project) => SendProjectEndDateReminderNotification::dispatch($project->id));
    }
}
