<?php

namespace App\Filament\Company\Resources\Sales\InvoiceResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Concerns\HideFromSpotlight;
use App\Concerns\ManagesLineItems;
use App\Enums\Accounting\InvoiceStatus;
use App\Filament\Company\Resources\Sales\InvoiceResource;
use App\Models\Accounting\Invoice;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditInvoice extends EditRecord
{
    use \App\Concerns\HideFromSpotlight;
    use HandlePageRedirect;
    use HideFromSpotlight;
    use ManagesLineItems;

    protected static string $resource = InvoiceResource::class;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        /** @var Invoice $invoice */
        $invoice = $this->record;

        if ($invoice->status === InvoiceStatus::Cancelled) {
            Notification::make()
                ->title('Cannot Edit Invoice')
                ->body('This invoice has been cancelled and cannot be edited.')
                ->danger()
                ->send();

            $this->redirect(ViewInvoice::getUrl(['record' => $invoice]));
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(function (Invoice $record) {
                    return $record->status !== InvoiceStatus::Cancelled;
                }),
        ];
    }

    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return MaxWidth::Full;
    }

    protected function onValidationError(ValidationException $exception): void
    {
        $message = $exception->validator->errors()->first('data.invoice_number')
            ?: $exception->validator->errors()->first('invoice_number');

        if ($message) {
            Notification::make()
                ->danger()
                ->title($message)
                ->send();
        }

        parent::onValidationError($exception);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Invoice $record */
        if ($record->status === InvoiceStatus::Cancelled) {
            Notification::make()
                ->title('Cannot Update Invoice')
                ->body('This invoice has been cancelled and cannot be updated.')
                ->danger()
                ->send();

            $this->redirect(ViewInvoice::getUrl(['record' => $record]));
        }

        $invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));

        if ($invoiceNumber !== '' && Invoice::isInvoiceNumberTaken($invoiceNumber, $record->company, $record->id)) {
            $message = $invoiceNumber . ' sudah digunakan';

            Notification::make()
                ->danger()
                ->title($message)
                ->send();

            throw ValidationException::withMessages([
                'data.invoice_number' => $message,
            ]);
        }

        $lineItems = collect($data['lineItems'] ?? []);

        $this->deleteRemovedLineItems($record, $lineItems);

        $this->handleLineItems($record, $lineItems);

        $totals = $this->updateDocumentTotals($record, $data);

        $data = array_merge($data, $totals);

        $record = parent::handleRecordUpdate($record, $data);

        if ($record->approved_at && $record->approvalTransaction) {
            $record->updateApprovalTransaction();
        }

        return $record;
    }
}
