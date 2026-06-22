<?php

namespace App\Filament\Actions;

use App\Filament\Actions\Concerns\FormatsProjectActivityLog;
use Rmsramos\Activitylog\Actions\ActivityLogTimelineTableAction;

class ProjectActivityLogTimelineTableAction extends ActivityLogTimelineTableAction
{
    use FormatsProjectActivityLog;

    protected function formatActivityData($activity): array
    {
        $data = parent::formatActivityData($activity);
        $data['properties'] = $this->formatProjectActivityProperties($data['properties'] ?? []);

        return $data;
    }
}
