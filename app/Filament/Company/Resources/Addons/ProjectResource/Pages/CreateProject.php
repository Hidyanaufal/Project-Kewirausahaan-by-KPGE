<?php

namespace App\Filament\Company\Resources\Addons\ProjectResource\Pages;

use App\Concerns\HasMediumContentWidth;
use App\Enums\Addons\ProjectStatus;
use App\Filament\Company\Resources\Addons\ProjectResource;
use App\Helpers\AuthHelper;
use App\Models\Addons\Project;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\Url;

class CreateProject extends CreateRecord
{
    use HasMediumContentWidth;

    #[Url(as: 'client')]
    public ?int $clientId = null;

    protected static string $resource = ProjectResource::class;

    public function mount(): void
    {
        parent::mount();

        if ($this->clientId) {
            $this->data['client_id'] = $this->clientId;
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['request_number_date'] = today();
        $data['status'] = AuthHelper::isAdmin() ? ProjectStatus::Approved : ProjectStatus::Draft;

        if ($data['status'] == ProjectStatus::Approved) {
            $data['number'] = Project::generateProjectNumber($data['project_type_id']);
        }

        return $data;
    }
}
