<?php

namespace App\Filament\Company\Resources\Accounting;

use App\Filament\Company\Resources\Accounting\JournalEntryResource\Pages;
use App\Filament\Company\Resources\Accounting\TransactionResource\Pages\ViewTransaction;
use App\Filament\Tables\Columns;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class JournalEntryResource extends Resource
{
    protected static ?string $model = JournalEntry::class;

    protected static ?string $modelLabel = 'Transaction';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query
                    ->with(['account', 'transaction'])
                    ->join('transactions', 'transactions.id', '=', 'journal_entries.transaction_id')
                    ->select('journal_entries.*', 'transactions.transaction_code', 'transactions.posted_at');
            })
            ->columns([
                Tables\Columns\TextColumn::make('transaction.transaction_code')
                    ->label('Transaction Code')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('transaction.posted_at')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('account.name')
                    ->label('Account')
                    ->description(fn($record) => $record->account?->code)
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(50),

                Tables\Columns\TextColumn::make('debit')
                    ->label('Debit')
                    ->weight(FontWeight::Bold)
                    ->color(Color::rgb('rgb(' . Color::Green[500] . ')'))
                    ->state(
                        fn(JournalEntry $record) =>
                        $record->type->value === 'debit'
                            ? number_format($record->amount / 100, 2)
                            : ''
                    ),

                Tables\Columns\TextColumn::make('credit')
                    ->label('Credit')
                    ->weight(FontWeight::Bold)
                    ->color(Color::rgb('rgb(' . Color::Red[500] . ')'))
                    ->state(
                        fn(JournalEntry $record) =>
                        $record->type->value === 'credit'
                            ? number_format($record->amount / 100, 2)
                            : ''
                    ),
            ])
            ->defaultGroup('transaction.transaction_code')
            ->groups([
                Tables\Grouping\Group::make('transaction.transaction_code')
                    ->label('Transaction')
                    ->collapsible(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('journal_entries.account_id')
                    ->label('Account')
                    ->options(Account::pluck('name', 'id'))
                    ->searchable(),

                Tables\Filters\Filter::make('posted_at')
                    ->label('Date Range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn($q, $date) => $q->whereDate('transactions.posted_at', '>=', $date))
                            ->when($data['until'], fn($q, $date) => $q->whereDate('transactions.posted_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn(JournalEntry $record) => ViewTransaction::getUrl(['record' => $record->transaction])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJournalEntries::route('/'),
        ];
    }
}
