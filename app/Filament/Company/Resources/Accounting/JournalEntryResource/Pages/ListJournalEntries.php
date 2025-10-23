<?php

namespace App\Filament\Company\Resources\Accounting\JournalEntryResource\Pages;

use App\Concerns\HasJournalEntryActions;
use App\Filament\Company\Resources\Accounting\JournalEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Enums\Accounting\TransactionType;
use App\Filament\Actions\CreateTransactionAction;
use App\Filament\Company\Pages\Service\ConnectedAccount;
use App\Models\Accounting\Transaction;
use App\Services\PlaidService;
use Filament\Support\Enums\IconPosition;

class ListJournalEntries extends ListRecords
{
    use HasJournalEntryActions;

    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                CreateTransactionAction::make('createDeposit')
                    ->label('Deposit')
                    ->model(Transaction::class)
                    ->type(TransactionType::Deposit),
                CreateTransactionAction::make('createWithdrawal')
                    ->label('Withdrawal')
                    ->model(Transaction::class)
                    ->type(TransactionType::Withdrawal),
                CreateTransactionAction::make('createTransfer')
                    ->label('Transfer')
                    ->model(Transaction::class)
                    ->type(TransactionType::Transfer),
                CreateTransactionAction::make('createJournalEntry')
                    ->label('Journal entry')
                    ->model(Transaction::class)
                    ->type(TransactionType::Journal),
            ])
                ->label('New transaction')
                ->button()
                ->dropdownPlacement('bottom-end')
                ->icon('heroicon-m-chevron-down')
                ->iconPosition(IconPosition::After),
            Actions\ActionGroup::make([
                Actions\Action::make('connectBank')
                    ->label('Connect your bank')
                    ->visible(app(PlaidService::class)->isEnabled())
                    ->url(ConnectedAccount::getUrl()),
            ])
                ->label('More')
                ->button()
                ->outlined()
                ->dropdownPlacement('bottom-end')
                ->icon('heroicon-m-chevron-down')
                ->iconPosition(IconPosition::After),
        ];
    }
}
