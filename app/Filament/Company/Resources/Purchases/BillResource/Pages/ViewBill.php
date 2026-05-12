<?php

namespace App\Filament\Company\Resources\Purchases\BillResource\Pages;

use App\Enums\Accounting\BillStatus;
use App\Filament\Company\Resources\Addons\ProjectResource;
use App\Filament\Company\Resources\Addons\ProjectResource\RelationManagers\AttachmentsRelationManager;
use App\Filament\Company\Resources\Purchases\BillResource;
use App\Filament\Company\Resources\Purchases\VendorResource;
use App\Helpers\AuthHelper;
use App\Models\Accounting\Bill;
use Filament\Actions;
use Filament\Forms\Components\Placeholder;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\MaxWidth;

class ViewBill extends ViewRecord
{
    use \App\Concerns\HideFromSpotlight;

    protected static string $resource = BillResource::class;

    protected $listeners = [
        'refresh' => '$refresh',
    ];

    protected function getHeaderActions(): array
    {
        $createBillWithProjectUrl = fn (): string => CreateBill::getUrl(array_filter([
            'project' => $this->record->project_id,
        ]));

        $showEdit = AuthHelper::isAdmin() || $this->record->status === BillStatus::Draft;
        $canCreate = BillResource::canCreate();

        $editBillAction = Actions\Action::make('editBill')
        ->label('Edit bill')
        ->outlined()
        ->url(fn (): string => BillResource::getUrl('edit', ['record' => $this->record]))
        ->visible(function () use ($showEdit, $canCreate): bool {
            return $canCreate && $showEdit;
        });

        $addBillsAction = Actions\Action::make('addBills')
            ->label('Add bills')
            ->outlined()
            ->url($createBillWithProjectUrl)
            ->visible(fn (): bool => $canCreate);

        $actionsGroup = Actions\ActionGroup::make([
            Actions\ActionGroup::make([
                Bill::getApproveDraftAction()
                    ->after(fn () => $this->dispatch('refresh')),
            ])->dropdown(false),
            Actions\ActionGroup::make([
                Actions\Action::make('journal_summary')
                    ->icon('heroicon-s-book-open')
                    ->outlined()
                    ->modalWidth(MaxWidth::SevenExtraLarge)
                    ->modalHeading('Journal Summary for ' . $this->record->bill_number)
                    ->slideOver()
                    ->modalFooterActions(fn () => [])
                    ->visible(function () {
                        return AuthHelper::isAdmin();
                    })
                    ->form([
                        Placeholder::make('journal_entries')
                            ->view('filament.company.resources.purchases.bill-resource.pages.journal-summary', [
                                'journalEntries' => $this->record?->transactions?->flatMap(fn ($transaction) => $transaction->journalEntries) ?? collect(),
                            ]),
                    ]),
                Actions\Action::make('journal_detail')
                    ->icon('heroicon-o-book-open')
                    ->outlined()
                    ->modalWidth(MaxWidth::SevenExtraLarge)
                    ->modalHeading('Journal Detail for ' . $this->record->bill_number)
                    ->slideOver()
                    ->modalFooterActions(fn () => [])
                    ->visible(function () {
                        return AuthHelper::isAdmin();
                    })
                    ->form([
                        Placeholder::make('journal_entries')
                            ->view('filament.company.resources.purchases.bill-resource.pages.journal-detail', [
                                'journalEntries' => $this->record?->transactions?->flatMap(fn ($transaction) => $transaction->journalEntries) ?? collect(),
                            ]),
                    ]),
            ])
                ->dropdown(false)
                ->visible(function () {
                    return AuthHelper::isAdmin();
                }),
            Actions\DeleteAction::make(),
        ])
            ->label('Actions')
            ->button()
            ->outlined()
            ->dropdownPlacement('bottom-end')
            ->icon('heroicon-m-chevron-down')
            ->iconPosition(IconPosition::After);

        $headerActions = [];

        if ($showEdit && $canCreate) {
            $headerActions[] = $editBillAction;
            $headerActions[] = $addBillsAction;
        } elseif ($canCreate) {
            $headerActions[] = $addBillsAction;
        }

        $headerActions[] = $actionsGroup;

        return $headerActions;
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Bill Details')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('bill_number')
                            ->label('Invoice #'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('vendor.name')
                            ->label('Vendor')
                            ->url(static fn (Bill $record) => $record->vendor_id ? VendorResource::getUrl('view', ['record' => $record->vendor_id]) : null)
                            ->link(),
                        TextEntry::make('vendor.notes')
                            ->label('Nomor Rekening / Nama Bank')
                            ->hidden(static fn (Bill $record) => empty($record->vendor?->notes)),
                        TextEntry::make('project.number')
                            ->label('Project')
                            ->url(static fn (Bill $record) => $record->project_id ? ProjectResource::getUrl('view', ['record' => $record->project_id]) : null)
                            ->link(),
                        TextEntry::make('total')
                            ->label('Total')
                            ->currency(static fn (Bill $record) => $record->currency_code),
                        TextEntry::make('amount_due')
                            ->label('Amount due')
                            ->currency(static fn (Bill $record) => $record->currency_code),
                        TextEntry::make('date')
                            ->label('Date')
                            ->date(),
                        TextEntry::make('due_date')
                            ->label('Due')
                            ->asRelativeDay(),
                        TextEntry::make('paid_at')
                            ->label('Paid at')
                            ->date(),
                        TextEntry::make('createdBy.name')
                            ->label('Created By'),
                        TextEntry::make('terms')
                            ->label('Notes Bill'),
                    ]),
            ]);
    }

    protected function getAllRelationManagers(): array
    {
        return [
            BillResource\RelationManagers\PaymentsRelationManager::class,
            AttachmentsRelationManager::class,
        ];
    }
}
