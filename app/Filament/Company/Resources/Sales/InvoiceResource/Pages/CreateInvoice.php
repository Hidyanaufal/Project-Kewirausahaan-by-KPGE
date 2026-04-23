<?php

namespace App\Filament\Company\Resources\Sales\InvoiceResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Concerns\ManagesLineItems;
use App\Enums\Accounting\InvoiceStatus;
use App\Filament\Company\Resources\Sales\InvoiceResource;
use App\Models\Accounting\Adjustment;
use App\Models\Accounting\Invoice;
use App\Models\Addons\Project;
use App\Models\Common\Client;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;

class CreateInvoice extends CreateRecord
{
    use HandlePageRedirect;
    use ManagesLineItems;

    protected static string $resource = InvoiceResource::class;

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
        $data['status'] ??= InvoiceStatus::Draft;

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

            $lockKey = 'invoice_number_lock_' . $company->id . '_' . now()->format('y');
            $lock = Cache::lock($lockKey, 15);
            $lock->block(10);

            $data['invoice_number'] = Invoice::getNextDocumentNumber($company);

            /** @var Invoice $record */
            $record = parent::handleRecordCreation($data);
            $this->handleLineItems($record, collect($data['lineItems'] ?? []));
            $totals = $this->updateDocumentTotals($record, $data);
            $record->updateQuietly($totals);

            // Greater than 10jt switch using PPn WAPU
            if ($record->isWapu()) {
                $wapuAdjusment = Adjustment::where([
                    'name' => 'PPN WAPU'
                ])->first();

                if (! $wapuAdjusment) {
                    throw new \Exception('This transaction exceeds 10,000,000. Please configure PPN WAPU in the adjustment settings before proceeding.');
                }

                $items = [];
                foreach ($data['lineItems'] as $item) {
                    $item['salesTaxes'] = [$wapuAdjusment->id];
                    $items[] = $item;
                }

                $lineItems = collect($items);
                $this->deleteRemovedLineItems($record, $lineItems);
                $this->handleLineItems($record, $lineItems);
                $totals = $this->updateDocumentTotals($record, $data);
                $record->updateQuietly($totals);
            }

            DB::commit();

            return $record;
        } catch (LockTimeoutException $e) {
            DB::rollBack();
            Notification::make()
                ->danger()
                ->title('Invoice creation busy')
                ->body('Another invoice is being created. Please try again in a moment.')
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

        // Unreachable karena $this->halt(), tapi diperlukan untuk kepuasan static analyzer.
        throw new \RuntimeException('Invoice creation halted.');
    }
}
