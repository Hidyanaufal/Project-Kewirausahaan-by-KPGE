<?php

namespace App\Filament\Company\Resources\Purchases\BillResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Concerns\ManagesLineItems;
use App\Enums\Accounting\BillStatus;
use App\Filament\Company\Resources\Purchases\BillResource;
use App\Models\Accounting\Bill;
use App\Models\Addons\Project;
use App\Models\Common\Vendor;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class CreateBill extends CreateRecord
{
    use HandlePageRedirect;
    use ManagesLineItems;

    protected static string $resource = BillResource::class;

    #[Url(as: 'vendor')]
    public ?int $vendorId = null;

    #[Url(as: 'project')]
    public ?int $projectId = null;

    public function mount(): void
    {
        parent::mount();

        if ($this->projectId) {
            $this->data['project_id'] = $this->projectId;
        }

        if ($this->vendorId) {
            $this->data['vendor_id'] = $this->vendorId;

            if ($currencyCode = Vendor::find($this->vendorId)?->currency_code) {
                $this->data['currency_code'] = $currencyCode;
            }
        }
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

            $lockKey = 'bill_number_lock_' . $company->id . '_' . now()->format('y');
            $lock = Cache::lock($lockKey, 15);
            $lock->block(10);

            $data['bill_number'] = Bill::getNextDocumentNumber($company);
            $data['status'] = BillStatus::Draft;

            /** @var Bill $record */
            $record = parent::handleRecordCreation($data);

            $this->handleLineItems($record, collect($data['lineItems'] ?? []));

            $totals = $this->updateDocumentTotals($record, $data);

            $record->updateQuietly($totals);

            if (! $record->initialTransaction) {
                $record->createInitialTransaction();
            }

            DB::commit();

            return $record;
        } catch (LockTimeoutException $e) {
            DB::rollBack();
            Notification::make()
                ->danger()
                ->title('Bill creation busy')
                ->body('Another bill is being created. Please try again in a moment.')
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
