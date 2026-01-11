<?php

namespace App\Filament\Company\Resources\Sales\InvoiceResource\Pages;

use App\Concerns\HideFromSpotlight;
use App\Enums\Accounting\DocumentType;
use App\Enums\Accounting\InvoiceStatus;
use App\Filament\Company\Resources\Addons\ProjectResource\RelationManagers\AttachmentsRelationManager;
use App\Filament\Company\Resources\Sales\ClientResource;
use App\Filament\Company\Resources\Sales\InvoiceResource;
use App\Filament\Infolists\Components\BannerEntry;
use App\Filament\Infolists\Components\DocumentPreview;
use App\Helpers\AuthHelper;
use App\Models\Accounting\Invoice;
use Filament\Actions;
use Filament\Forms\Components\Placeholder;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class ViewInvoice extends ViewRecord
{

    use \App\Concerns\HideFromSpotlight;
    use HideFromSpotlight;

    protected static string $resource = InvoiceResource::class;

    protected $listeners = [
        'refresh' => '$refresh',
    ];

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->invoice_number;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('edit')
                ->label('Edit Invoice')
                ->outlined()
                ->requiresConfirmation()
                ->action(function (Model $record) {
                    $isAdmin = AuthHelper::isAdmin();
                    if (!$isAdmin) {
                        $record->status = InvoiceStatus::Draft;
                        $record->approved_at = null;
                        $record->save();
                    }
                    return redirect(EditInvoice::getUrl(['record' => $record]));
                }),
            Actions\ActionGroup::make([
                Actions\ActionGroup::make([
                    Invoice::getApproveDraftAction(),
                    Invoice::getMarkAsSentAction(),
                    Invoice::getPrintDocumentAction(),
                    Invoice::getCancelInvoiceAction(Actions\Action::class),
                ])->dropdown(false),
                Actions\ActionGroup::make([
                    Actions\Action::make('journal_summary')
                        ->icon('heroicon-s-book-open')
                        ->outlined()
                        ->modalWidth(MaxWidth::SevenExtraLarge)
                        ->modalHeading('Journal Summary for ' . $this->record->invoice_number)
                        ->slideOver()
                        ->modalFooterActions(fn() => [])
                        ->form([
                            Placeholder::make('journal_entries')
                                ->view('filament.company.resources.sales.invoice-resource.pages.journal-summary', [
                                    'journalEntries' => $this->record?->transactions?->flatMap(fn($transaction) => $transaction->journalEntries) ?? [],
                                ]),
                        ]),
                    Actions\Action::make('journal_detail')
                        ->icon('heroicon-o-book-open')
                        ->outlined()
                        ->modalWidth(MaxWidth::SevenExtraLarge)
                        ->modalHeading('Journal Detail for ' . $this->record->invoice_number)
                        ->slideOver()
                        ->modalFooterActions(fn() => [])
                        ->form([
                            Placeholder::make('journal_entries')
                                ->view('filament.company.resources.sales.invoice-resource.pages.journal-detail', [
                                    'journalEntries' => $this->record?->transactions?->flatMap(fn($transaction) => $transaction->journalEntries) ?? [],
                                ]),
                        ]),
                ])->dropdown(false),
                Actions\DeleteAction::make(),
            ])
                ->label('Actions')
                ->button()
                ->outlined()
                ->dropdownPlacement('bottom-end')
                ->icon('heroicon-m-chevron-down')
                ->iconPosition(IconPosition::After),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                BannerEntry::make('legacy_flag')
                    ->warning()
                    ->icon('heroicon-o-exclamation-triangle')
                    ->columnSpanFull()
                    ->visible(fn(Invoice $record) => $record->is_imported)
                    ->title('Legacy Data Notice')
                    ->description(function () {
                        return 'This data was imported from the previous system. It may contain inconsistencies or may not fully comply with the current data-handling standards.';
                    }),
                BannerEntry::make('inactiveAdjustments')
                    ->label('Inactive adjustments')
                    ->warning()
                    ->icon('heroicon-o-exclamation-triangle')
                    ->visible(fn(Invoice $record) => $record->hasInactiveAdjustments() && $record->canBeApproved())
                    ->columnSpanFull()
                    ->description(function (Invoice $record) {
                        $inactiveAdjustments = collect();

                        foreach ($record->lineItems as $lineItem) {
                            foreach ($lineItem->adjustments as $adjustment) {
                                if ($adjustment->isInactive() && $inactiveAdjustments->doesntContain($adjustment->name)) {
                                    $inactiveAdjustments->push($adjustment->name);
                                }
                            }
                        }

                        $adjustmentsList = $inactiveAdjustments->map(static function ($name) {
                            return "<span class='font-medium'>{$name}</span>";
                        })->join(', ');

                        $output = "<p class='text-sm'>This invoice contains inactive adjustments that need to be addressed before approval: {$adjustmentsList}</p>";

                        return new HtmlString($output);
                    }),
                Section::make('Invoice Details')
                    ->columns(4)
                    ->schema([
                        Grid::make(1)
                            ->schema([
                                TextEntry::make('invoice_number')
                                    ->label('Invoice #'),
                                TextEntry::make('status')
                                    ->badge(),
                                TextEntry::make('client.name')
                                    ->label('Client')
                                    ->url(static fn(Invoice $record) => $record->client_id ? ClientResource::getUrl('view', ['record' => $record->client_id]) : null)
                                    ->link(),
                                TextEntry::make('amount_due')
                                    ->label('Amount due')
                                    ->currency(static fn(Invoice $record) => $record->currency_code),
                                TextEntry::make('due_date')
                                    ->label('Due')
                                    ->asRelativeDay(),
                                TextEntry::make('approved_at')
                                    ->label('Approved at')
                                    ->date(),
                                TextEntry::make('last_sent_at')
                                    ->label('Last sent')
                                    ->date(),
                                TextEntry::make('paid_at')
                                    ->label('Paid at')
                                    ->date(),
                                TextEntry::make('createdBy.name')
                                    ->label('Created By'),
                            ])->columnSpan(1),
                        DocumentPreview::make()
                            ->type(DocumentType::Invoice),
                    ]),
            ]);
    }

    protected function getAllRelationManagers(): array
    {
        return [
            InvoiceResource\RelationManagers\PaymentsRelationManager::class,
            AttachmentsRelationManager::class
        ];
    }
}
