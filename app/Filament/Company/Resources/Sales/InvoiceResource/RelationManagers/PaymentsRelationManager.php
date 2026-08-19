<?php

namespace App\Filament\Company\Resources\Sales\InvoiceResource\RelationManagers;

use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Accounting\PaymentMethod;
use App\Enums\Accounting\TransactionType;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\Transaction;
use App\Models\Banking\BankAccount;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $modelLabel = 'Payment';

    protected static bool $isLazy = false;

    protected $listeners = [
        'refresh' => '$refresh',
    ];

    public function isReadOnly(): bool
    {
        return false;
    }

    protected function canEdit(Model $record): bool
    {
        /** @var Invoice $ownerRecord */
        $ownerRecord = $this->getOwnerRecord();

        if (! $ownerRecord->canManagePayments()) {
            return false;
        }

        if (! $record instanceof Transaction || ! $record->is_payment) {
            return false;
        }

        return (int) $record->transactionable_id === (int) $ownerRecord->id;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->status !== InvoiceStatus::Draft;
    }

    public function form(Form $form): Form
    {
        return $form
            ->columns(1)
            ->schema([
                Forms\Components\TextInput::make('transaction_code')
                    ->label('Transaction code')
                    ->default(fn () => Transaction::getNextDocumentNumber(TransactionType::Deposit)),
                Forms\Components\DatePicker::make('posted_at')
                    ->label('Date'),
                Forms\Components\Grid::make()
                    ->schema([
                        Forms\Components\Select::make('bank_account_id')
                            ->label('Account')
                            ->required()
                            ->live()
                            ->options(function () {
                                return BankAccount::query()
                                    ->join('accounts', 'bank_accounts.account_id', '=', 'accounts.id')
                                    ->select(['bank_accounts.id', 'accounts.name', 'accounts.currency_code'])
                                    ->get()
                                    ->mapWithKeys(function ($account) {
                                        $label = $account->name;
                                        if ($account->currency_code) {
                                            $label .= " ({$account->currency_code})";
                                        }

                                        return [$account->id => $label];
                                    })
                                    ->toArray();
                            })
                            ->searchable(),
                        Forms\Components\TextInput::make('amount')
                            ->label('Amount')
                            ->required()
                            ->money(function (RelationManager $livewire) {
                                /** @var Invoice $invoice */
                                $invoice = $livewire->getOwnerRecord();

                                return $invoice->currency_code;
                            })
                            ->live(onBlur: true)
                            ->helperText(function (Forms\Get $get, RelationManager $livewire, $state, ?Transaction $record) {
                                /** @var Invoice $ownerRecord */
                                $ownerRecord = $livewire->getOwnerRecord();

                                $invoiceCurrency = $ownerRecord->currency_code;

                                if (! CurrencyConverter::isValidAmount($state, 'USD')) {
                                    return null;
                                }

                                $amount = CurrencyConverter::convertToCents($state, 'USD');

                                if ($amount <= 0) {
                                    return 'Please enter a valid positive amount';
                                }

                                $currentPaymentAmount = (int) ($record?->meta['amount_in_document_currency_cents'] ?? $record?->amount ?? 0);

                                if ($ownerRecord->status === InvoiceStatus::Overpaid) {
                                    $amountDue = $ownerRecord->amount_due;
                                    $newAmountDue = $amountDue + $amount - $currentPaymentAmount;
                                } else {
                                    $pph23Cents = self::parseMoneyStateToCents($get('pph23'));
                                    $amountDue = $ownerRecord->paymentAmountForPph23($pph23Cents);
                                    $newAmountDue = $amountDue - $amount + $currentPaymentAmount;
                                }

                                return match (true) {
                                    $newAmountDue > 0 => 'Amount due after payment will be ' . CurrencyConverter::formatCentsToMoney($newAmountDue, $invoiceCurrency),
                                    $newAmountDue === 0 => 'Invoice will be fully paid',
                                    default => 'Invoice will be overpaid by ' . CurrencyConverter::formatCentsToMoney(abs($newAmountDue), $invoiceCurrency),
                                };
                            })
                            ->rules([
                                static fn(): Closure => static function (string $attribute, $value, Closure $fail) {
                                    if (! CurrencyConverter::isValidAmount($value, 'USD')) {
                                        $fail('Please enter a valid amount');
                                    }
                                },
                            ]),
                        Forms\Components\TextInput::make('pph23')
                            ->label('PPh 23')
                            ->nullable()
                            ->money(function (RelationManager $livewire) {
                                /** @var Invoice $invoice */
                                $invoice = $livewire->getOwnerRecord();

                                return $invoice->currency_code;
                            })
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Forms\Set $set, RelationManager $livewire) {
                                /** @var Invoice $invoice */
                                $invoice = $livewire->getOwnerRecord();
                                $pph23Cents = self::parseMoneyStateToCents($state);
                                $amountCents = $invoice->paymentAmountForPph23($pph23Cents);

                                $set('amount', CurrencyConverter::convertCentsToFormatSimple($amountCents, 'USD'));
                            }),
                    ])->columns(3),
                Forms\Components\Placeholder::make('currency_conversion')
                    ->label('Currency Conversion')
                    ->content(function (Forms\Get $get, RelationManager $livewire) {
                        $amount = $get('amount');
                        $bankAccountId = $get('bank_account_id');

                        /** @var Invoice $invoice */
                        $invoice = $livewire->getOwnerRecord();
                        $invoiceCurrency = $invoice->currency_code;

                        if (empty($amount) || empty($bankAccountId) || ! CurrencyConverter::isValidAmount($amount, 'USD')) {
                            return null;
                        }

                        $bankAccount = BankAccount::with('account')->find($bankAccountId);
                        if (! $bankAccount) {
                            return null;
                        }

                        $bankCurrency = $bankAccount->account->currency_code ?? CurrencyAccessor::getDefaultCurrency();

                        // If currencies are the same, no conversion needed
                        if ($invoiceCurrency === $bankCurrency) {
                            return null;
                        }

                        // Convert amount from invoice currency to bank currency
                        $amountInInvoiceCurrencyCents = CurrencyConverter::convertToCents($amount, 'USD');
                        $amountInBankCurrencyCents = CurrencyConverter::convertBalance(
                            $amountInInvoiceCurrencyCents,
                            $invoiceCurrency,
                            $bankCurrency
                        );

                        $formattedBankAmount = CurrencyConverter::formatCentsToMoney($amountInBankCurrencyCents, $bankCurrency);

                        return "Payment will be recorded as {$formattedBankAmount} in the bank account's currency ({$bankCurrency}).";
                    })
                    ->hidden(function (Forms\Get $get, RelationManager $livewire) {
                        $bankAccountId = $get('bank_account_id');
                        if (empty($bankAccountId)) {
                            return true;
                        }

                        /** @var Invoice $invoice */
                        $invoice = $livewire->getOwnerRecord();
                        $invoiceCurrency = $invoice->currency_code;

                        $bankAccount = BankAccount::with('account')->find($bankAccountId);
                        if (! $bankAccount) {
                            return true;
                        }

                        $bankCurrency = $bankAccount->account->currency_code ?? CurrencyAccessor::getDefaultCurrency();

                        // Hide if currencies are the same
                        return $invoiceCurrency === $bankCurrency;
                    }),
                Forms\Components\Select::make('payment_method')
                    ->label('Payment method')
                    ->required()
                    ->options(PaymentMethod::class),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
        ->emptyStateHeading('No payments recorded')
        ->emptyStateDescription('Please approve this invoice before recording any payments')
            ->recordTitleAttribute('description')
            ->columns([
                Tables\Columns\TextColumn::make('posted_at')
                    ->label('Date')
                    ->sortable()
                    ->defaultDateFormat(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(30)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('bankAccount.account.name')
                    ->label('Account')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->weight(static fn(Transaction $transaction) => $transaction->reviewed ? null : FontWeight::SemiBold)
                    ->color(
                        static fn(Transaction $transaction) => match ($transaction->type) {
                            TransactionType::Deposit => Color::rgb('rgb(' . Color::Green[700] . ')'),
                            TransactionType::Journal => 'primary',
                            default => null,
                        }
                    )
                    ->sortable()
                    ->currency(static fn(Transaction $transaction) => $transaction->bankAccount?->account->currency_code ?? CurrencyAccessor::getDefaultCurrency()),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(fn() => $this->getOwnerRecord()->status === InvoiceStatus::Overpaid ? 'Refund Overpayment' : 'Record Payment')
                    ->modalHeading(fn(Tables\Actions\CreateAction $action) => $action->getLabel())
                    ->slideOver()
                    ->modalWidth(MaxWidth::TwoExtraLarge)
                    ->visible(function () {
                        return $this->getOwnerRecord()->canRecordPayment() && $this->getOwnerRecord()->canManagePayments();
                    })
                    ->mountUsing(function (Form $form) {
                        /** @var Invoice $record */
                        $record = $this->getOwnerRecord()->fresh();
                        $defaultPph23 = $record->defaultPph23ForPaymentForm();

                        $form->fill([
                            'transaction_code' => Transaction::getNextDocumentNumber(TransactionType::Deposit),
                            'posted_at' => company_today()->toDateString(),
                            'amount' => max($record->paymentAmountForPph23($defaultPph23), 0),
                            'pph23' => $defaultPph23 ?: null,
                            'payment_method' => PaymentMethod::BankPayment,
                        ]);
                    })
                    ->databaseTransaction()
                    ->successNotificationTitle('Payment recorded')
                    ->action(function (Tables\Actions\CreateAction $action, array $data) {
                        /** @var Invoice $record */
                        $record = $this->getOwnerRecord();

                        DB::transaction(function () use ($record, $data) {
                            if ($record->status === InvoiceStatus::Overpaid) {
                                $record->recordPayment($data);

                                return;
                            }

                            $record->recordPaymentWithPph23($data);
                        });

                        $action->success();
                        $this->dispatch('refresh');
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Edit')
                    ->modalHeading('Edit Payment')
                    ->slideOver()
                    ->modalWidth(MaxWidth::TwoExtraLarge)
                    ->mountUsing(function (Form $form, Transaction $record) {
                        $form->fill([
                            'transaction_code' => $record->transaction_code,
                            'posted_at' => $record->posted_at,
                            'bank_account_id' => $record->bank_account_id,
                            'amount' => $record->meta['amount_in_document_currency_cents'] ?? $record->amount,
                            'pph23' => $record->meta['pph23_cents'] ?? null,
                            'payment_method' => $record->payment_method,
                            'notes' => $record->notes,
                        ]);
                    })
                    ->databaseTransaction()
                    ->successNotificationTitle('Payment updated')
                    ->action(function (Tables\Actions\EditAction $action, Transaction $record, array $data) {
                        /** @var Invoice $ownerRecord */
                        $ownerRecord = $this->getOwnerRecord();

                        DB::transaction(function () use ($ownerRecord, $record, $data) {
                            $ownerRecord->updatePaymentWithPph23($record, $data);
                        });

                        $action->success();
                        $this->dispatch('refresh');
                    }),
                Tables\Actions\DeleteAction::make()
                    ->after(fn() => $this->dispatch('refresh')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function parseMoneyStateToCents(mixed $state): int
    {
        if (blank($state)) {
            return 0;
        }

        if (is_int($state)) {
            return $state;
        }

        $string = (string) $state;

        if (! CurrencyConverter::isValidAmount($string, 'USD')) {
            return 0;
        }

        return CurrencyConverter::convertToCents($string, 'USD');
    }
}
