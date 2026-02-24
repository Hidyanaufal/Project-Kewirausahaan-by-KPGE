<?php

namespace App\Jobs;

use App\Notifications\ExportCompletionNotification;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\Jobs\ExportCompletion as BaseExportCompletion;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;

class ExportCompletion extends BaseExportCompletion
{
    public function handle(): void
    {
        $this->export->touch('completed_at');

        if (! $this->export->user instanceof Authenticatable) {
            return;
        }

        $failedRowsCount = $this->export->getFailedRowsCount();
        $exporter = $this->exporter;

        $notification = ExportCompletionNotification::make()
            ->title($exporter::getCompletedNotificationTitle($this->export))
            ->body($exporter::getCompletedNotificationBody($this->export))
            ->when(
                ! $failedRowsCount,
                fn (Notification $n) => $n->success(),
            )
            ->when(
                $failedRowsCount && ($failedRowsCount < $this->export->total_rows),
                fn (Notification $n) => $n->warning(),
            )
            ->when(
                $failedRowsCount === $this->export->total_rows,
                fn (Notification $n) => $n->danger(),
            )
            ->when(
                $failedRowsCount < $this->export->total_rows,
                fn (Notification $n) => $n->actions(array_map(
                    fn (ExportFormat $format): NotificationAction => $format->getDownloadNotificationAction($this->export),
                    $this->formats,
                )),
            )
            ->companyId($this->export->company_id ?? $this->export->user?->current_company_id);

        if (($this->connection === 'sync') ||
            (blank($this->connection) && (config('queue.default') === 'sync'))) {
            $notification->persistent()->send();
        } else {
            $notification->sendToDatabase($this->export->user, isEventDispatched: true);
        }
    }
}
