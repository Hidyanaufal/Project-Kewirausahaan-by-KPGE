<?php

namespace App\Console\Commands;

use App\Jobs\SendProjectEndDateRemindersJob;
use Illuminate\Console\Command;

class SendProjectEndDateReminders extends Command
{
    protected $signature = 'app:send-project-end-date-reminders';

    protected $description = 'Send end-date reminder emails for projects that ended yesterday';

    public function handle(): void
    {
        SendProjectEndDateRemindersJob::dispatch();
    }
}
