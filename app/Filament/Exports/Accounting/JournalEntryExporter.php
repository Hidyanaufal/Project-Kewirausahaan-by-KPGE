<?php

namespace App\Filament\Exports\Accounting;

use App\Filament\Exports\BaseExporter;
use App\Models\Accounting\JournalEntry;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class JournalEntryExporter extends BaseExporter
{
    protected static ?string $model = JournalEntry::class;

    public static function modifyQuery(Builder $query): Builder
    {
        $query = parent::modifyQuery($query);

        return $query->where(function (Builder $q): void {
            $q->whereNull('journal_entries.description')
                ->orWhere(function (Builder $q2): void {
                    $q2->where('journal_entries.description', '!=', 'Auto Import')
                        ->where('journal_entries.description', 'not like', 'Saldo Awal FY-%');
                });
        });
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('transaction.transaction_code')
                ->label('Transaction Code'),
            ExportColumn::make('transaction.posted_at')
                ->label('Date')
                ->date(),
            ExportColumn::make('account.name')
                ->label('Account'),
            ExportColumn::make('description')
                ->label('Description'),
            ExportColumn::make('debit')
                ->label('Debit')
                ->state(static function (JournalEntry $record): ?string {
                    return $record->type->value === 'debit' ? ($record->amount / 100) : null;
                }),
            ExportColumn::make('credit')
                ->label('Credit')
                ->state(static function (JournalEntry $record): ?string {
                    return $record->type->value === 'credit' ? ($record->amount / 100) : null;
                }),
            ExportColumn::make('transaction.transactionable.invoice_number')
                ->label('Invoice Number')
                ->enabledByDefault(false),
            ExportColumn::make('transaction.transactionable.bill_number')
                ->label('Bill Number')
                ->enabledByDefault(false),
            ExportColumn::make('transaction.type')
                ->label('Transaction Type')
                ->enum()
                ->enabledByDefault(false),
            ExportColumn::make('transaction.reviewed')
                ->label('Reviewed')
                ->enabledByDefault(false),
            ExportColumn::make('transaction.is_payment')
                ->label('Payment')
                ->enabledByDefault(false),
            ExportColumn::make('transaction.member.name')
                ->label('Member')
                ->enabledByDefault(false),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your journal entry export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
