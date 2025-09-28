<?php

namespace App\Filament\Company\Resources\Sales\EstimateResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Concerns\ManagesLineItems;
use App\Filament\Company\Resources\Sales\EstimateResource;
use App\Models\Accounting\Estimate;
use App\Models\Addons\Project;
use App\Models\Common\Client;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;

class CreateEstimate extends CreateRecord
{
    use HandlePageRedirect;
    use ManagesLineItems;

    protected static string $resource = EstimateResource::class;

    #[Url(as: 'client')]
    public ?int $clientId = null;

    #[Url(as: 'project')]
    public ?int $projectId = null;

    public function mount(): void
    {
        parent::mount();

        if ($this->projectId) {
            $this->data['project_id'] = $this->projectId;
            $this->clientId = Project::find($this->projectId)->client_id;
        }

        if ($this->clientId) {
            $this->data['client_id'] = $this->clientId;

            if ($currencyCode = Client::find($this->clientId)?->currency_code) {
                $this->data['currency_code'] = $currencyCode;
            }
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data;
    }

    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return MaxWidth::Full;
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Estimate $record */
        $record = parent::handleRecordCreation($data);

        $this->handleLineItems($record, collect($data['lineItems'] ?? []));

        $totals = $this->updateDocumentTotals($record, $data);

        $record->updateQuietly($totals);

        return $record;
    }
}
