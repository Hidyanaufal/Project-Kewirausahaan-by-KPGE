<?php

namespace App\Filament\Company\Resources\Accounting\TransactionResource\Pages;

use App\Filament\Company\Resources\Accounting\JournalEntryResource\Pages\ListJournalEntries;
use App\Filament\Company\Resources\Accounting\TransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    public function mount(): void
    {
        parent::mount();
        redirect(ListJournalEntries::getUrl());
    }
}
