<?php

namespace App\Filament\Company\Resources\Sales\EstimateResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Concerns\ManagesLineItems;
use App\Enums\Accounting\EstimateStatus;
use App\Filament\Company\Resources\Sales\EstimateResource;
use App\Models\Accounting\Estimate;
use App\Models\Addons\Project;
use App\Models\Common\Client;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        // Pastikan status ter-set agar observer (created) bisa kirim email submission.
        $data['status'] ??= EstimateStatus::Draft;

        return $data;
    }

    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return MaxWidth::Full;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $lock = null;

        try {
            DB::beginTransaction();

            $company = auth()->user()?->currentCompany;
            if (! $company) {
                throw new \RuntimeException('No current company is set for the user.');
            }

            $lockKey = 'estimate_number_lock_' . $company->id . '_' . now()->format('y');
            $lock = Cache::lock($lockKey, 15);
            $lock->block(10);

            $data['estimate_number'] = Estimate::getNextDocumentNumber($company);

            /** @var Estimate $record */
            $record = parent::handleRecordCreation($data);

            $this->handleLineItems($record, collect($data['lineItems'] ?? []));

            $totals = $this->updateDocumentTotals($record, $data);

            $record->updateQuietly($totals);

            DB::commit();

            return $record;
        } catch (LockTimeoutException $e) {
            DB::rollBack();
            Notification::make()
                ->danger()
                ->title('Estimate creation busy')
                ->body('Another estimate is being created. Please try again in a moment.')
                ->send();
            $this->halt();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Notification::make()
                ->danger()
                ->title('Something went wrong')
                ->body($e->getMessage())
                ->send();
            $this->halt();
            throw $e;
        } finally {
            if ($lock !== null) {
                $lock->release();
            }
        }
    }
}
