<?php

namespace App\Filament\Company\Resources\Accounting;

use App\Concerns\HasPermissionCheck;
use App\Filament\Company\Resources\Accounting\JournalEntryResource\Pages;
use App\Filament\Company\Resources\Accounting\TransactionResource\Pages\ViewTransaction;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Account;
use App\Models\Addons\Member;
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
    use HasPermissionCheck;

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
                Tables\Columns\TextColumn::make('transaction.transactionable.invoice_number')
                    ->label('Invoice Number')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('transaction', function (Builder $q) use ($search) {
                            $q->whereHasMorph(
                                'transactionable',
                                [\App\Models\Accounting\Invoice::class],
                                function (Builder $q) use ($search) {
                                    $q->where('invoice_number', $search);
                                }
                            );
                        });
                    })                    
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('transaction.transactionable.bill_number')
                    ->label('Bill Number')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('transaction', function (Builder $q) use ($search) {
                            $q->whereHasMorph(
                                'transactionable',
                                [\App\Models\Accounting\Bill::class],
                                function (Builder $q) use ($search) {
                                    $q->where('bill_number', $search);
                                }
                            );
                        });
                    })                    
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->groups([
                Tables\Grouping\Group::make('transaction.transaction_code')
                    ->label('Transaction')
                    ->collapsible(),
            ])
            ->filters([
                // Filter akun
                Tables\Filters\SelectFilter::make('journal_entries.account_id')
                    ->native(false)
                    ->label('Account')
                    ->options(Account::pluck('name', 'id'))
                    ->searchable(),

                // Filter tipe transaksi
                Tables\Filters\SelectFilter::make('transactions.type')
                    ->native(false)
                    ->label('Type')
                    ->options([
                        'deposit' => 'Deposit',
                        'withdrawal' => 'Withdrawal',
                        'journal' => 'Journal',
                    ]),

                // Filter status reviewed
                Tables\Filters\SelectFilter::make('transactions.reviewed')
                    ->native(false)
                    ->label('Reviewed')
                    ->options([
                        1 => 'Reviewed',
                        0 => 'Not Reviewed',
                    ]),

                // Filter payment
                Tables\Filters\SelectFilter::make('transactions.is_payment')
                    ->native(false)
                    ->label('Payment')
                    ->options([
                        1 => 'Payment',
                        0 => 'Non-payment',
                    ]),

                // Filter member
                Tables\Filters\SelectFilter::make('transactions.member_id')
                    ->native(false)
                    ->label('Member')
                    ->options(fn() => Member::orderBy('name')->pluck('name', 'id')->toArray())
                    ->searchable(),

                // Filter tanggal transaksi
                Tables\Filters\Filter::make('transactions.posted_at')
                    ->label('Posted Date')
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
