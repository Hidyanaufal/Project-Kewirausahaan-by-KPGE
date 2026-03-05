<?php

namespace App\Notifications;

use Filament\Notifications\Notification as BaseNotification;

class ExportCompletionNotification extends BaseNotification
{
    protected ?int $companyId = null;

    public function companyId(?int $companyId): static
    {
        $this->companyId = $companyId;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDatabaseMessage(): array
    {
        $data = parent::getDatabaseMessage();

        if ($this->companyId !== null) {
            $data['company_id'] = $this->companyId;
        }

        return $data;
    }
}
