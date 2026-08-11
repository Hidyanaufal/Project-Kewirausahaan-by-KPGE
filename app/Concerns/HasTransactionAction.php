<?php

namespace App\Concerns;

use App\Enums\Accounting\JournalEntryType;
use App\Enums\Accounting\PaymentMethod;
use App\Enums\Accounting\TransactionType;
use App\Filament\Company\Resources\Addons\MemberResource;
use App\Filament\Company\Resources\Addons\ProjectResource;
use App\Filament\Forms\Components\CustomTableRepeater;
use App\Models\Accounting\Account;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
use App\Models\Banking\BankAccount;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use Awcodes\TableRepeater\Header;
use Closure;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Form;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

trait HasTransactionAction
{
    use HasJournalEntryActions;

    protected TransactionType | Closure | null $transactionType = null;

    public function type(TransactionType | Closure | null $type = null): static
    {
        $this->transactionType = $type;

        return $this;
    }

    public function getTransactionType(): ?TransactionType
    {
        return $this->evaluate($this->transactionType);
    }

    protected function getFormDefaultsForType(TransactionType $type): array
    {
        $commonDefaults = [
            'posted_at' => company_today()->toDateString(),
            'bank_account_id' => BankAccount::defaultId(),
            'transaction_code' => Transaction::getNextDocumentNumber($type)
        ];

        return match ($type) {
            TransactionType::Deposit, TransactionType::Withdrawal, TransactionType::Transfer => array_merge($commonDefaults, $this->transactionDefaults($type)),
            TransactionType::Journal => array_merge($commonDefaults, $this->journalEntryDefaults()),
        };
    }

    protected function journalEntryDefaults(): array
    {
        return [
            'journalEntries' => [
                $this->defaultEntry(JournalEntryType::Debit),
                $this->defaultEntry(JournalEntryType::Credit),
            ],
        ];
    }

    protected function defaultEntry(JournalEntryType $journalEntryType): array
    {
        return [
            'type' => $journalEntryType,
            'amount' => '0.00',
        ];
    }

    protected function transactionDefaults(TransactionType $type): array
    {
        return [
            'type' => $type,
            'bank_account_id' => BankAccount::where('enabled', true)->first()?->id,
            'amount' => '0.00',
        ];
    }

    public function transactionForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('id'),
                Forms\Components\TextInput::make('transaction_code')
                    ->required(false),
                Forms\Components\DatePicker::make('posted_at')
                    ->label('Date')
                    ->required(),
                Forms\Components\TextInput::make('description')
                    ->label('Description'),
                Forms\Components\Select::make('bank_account_id')
                    ->label('Account')
                    ->options(fn(?Transaction $transaction) => Transaction::getBankAccountOptions(currentBankAccountId: $transaction?->bank_account_id))
                    ->live()
                    ->searchable()
                    ->required(),
                Forms\Components\Select::make('type')
                    ->label('Type')
                    ->live()
                    ->options([
                        TransactionType::Deposit->value => TransactionType::Deposit->getLabel(),
                        TransactionType::Withdrawal->value => TransactionType::Withdrawal->getLabel(),
                    ])
                    ->required()
                    ->afterStateUpdated(static fn(Forms\Set $set, $state) => $set('account_id', Transaction::getUncategorizedAccountByType(TransactionType::parse($state))?->id)),
                Forms\Components\TextInput::make('amount')
                    ->label('Amount')
                    ->money(static fn(Forms\Get $get) => BankAccount::find($get('bank_account_id'))?->account?->currency_code ?? CurrencyAccessor::getDefaultCurrency())
                    ->required(),
                Forms\Components\TextInput::make('pph23')
                    ->label('PPh 23')
                    ->nullable()
                    ->money(static function (?Transaction $record) {
                        if ($record?->transactionable instanceof Invoice) {
                            return $record->transactionable->currency_code;
                        }

                        return CurrencyAccessor::getDefaultCurrency();
                    })
                    ->visible(fn (?Transaction $record): bool => $this->shouldShowPph23Field($record)),
                Forms\Components\Select::make('account_id')
                    ->label('Category')
                    ->options(function (Forms\Get $get, ?Transaction $transaction) {
                        $type = $get('type') ?? $transaction?->type?->value ?? TransactionType::Deposit->value;

                        return Transaction::getTransactionAccountOptions(
                            type: TransactionType::parse($type),
                            currentAccountId: $transaction?->account_id ?? (is_numeric($get('account_id')) ? (int) $get('account_id') : null),
                        );
                    })
                    ->getOptionLabelUsing(fn ($value): ?string => Account::query()->find($value)?->name)
                    ->searchable()
                    ->required(),
                ProjectResource::options()->required(false),
                MemberResource::options()->required(false),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->autosize()
                    ->rows(10)
                    ->columnSpanFull(),
            ])
            ->columns();
    }

    protected function shouldShowPph23Field(?Transaction $record): bool
    {
        return (bool) $record?->is_payment
            && $record->transactionable_type === Invoice::class;
    }

    protected function fillPph23IntoRecordData(array $data, Transaction $record): array
    {
        if (! $this->shouldShowPph23Field($record)) {
            return $data;
        }

        $data['pph23'] = $record->meta['pph23_cents']
            ?? ($record->transactionable instanceof Invoice ? $record->transactionable->pph23 : null);

        return $data;
    }

    protected function saveTransactionFromForm(Transaction $record, array $data): Transaction
    {
        if ($record->is_payment && $record->transactionable instanceof Invoice) {
            return $this->saveInvoicePaymentTransaction($record, $data);
        }

        unset($data['pph23'], $data['id']);

        $record->update($data);

        return $record;
    }

    protected function saveInvoicePaymentTransaction(Transaction $record, array $data): Transaction
    {
        /** @var Invoice $invoice */
        $invoice = $record->transactionable;

        $pph23Cents = array_key_exists('pph23', $data) && $data['pph23'] !== null && $data['pph23'] !== ''
            ? (int) $data['pph23']
            : null;

        $amountInDocumentCurrency = (int) ($data['amount'] ?? $record->amount);

        // Keep document-currency amount when the bank amount was not changed.
        if (
            isset($record->meta['amount_in_document_currency_cents'])
            && (int) ($data['amount'] ?? $record->amount) === (int) $record->amount
        ) {
            $amountInDocumentCurrency = (int) $record->meta['amount_in_document_currency_cents'];
        }

        $invoice->updatePayment($record, [
            'posted_at' => $data['posted_at'],
            'bank_account_id' => $data['bank_account_id'],
            'amount' => $amountInDocumentCurrency,
            'payment_method' => $data['payment_method'] ?? $record->payment_method ?? PaymentMethod::BankPayment,
            'transaction_code' => $data['transaction_code'] ?? null,
            'notes' => $data['notes'] ?? null,
            'pph23' => $pph23Cents,
        ]);

        Transaction::withoutEvents(function () use ($record, $data): void {
            $record->update([
                'description' => $data['description'] ?? $record->description,
                'account_id' => $data['account_id'] ?? $record->account_id,
                'project_id' => $data['project_id'] ?? null,
                'member_id' => $data['member_id'] ?? null,
                'type' => $data['type'] ?? $record->type,
            ]);
        });

        return $record->refresh();
    }

    public function transferForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('id'),
                Forms\Components\TextInput::make('transaction_code')
                    ->columnSpanFull()
                    ->required(false),
                Forms\Components\DatePicker::make('posted_at')
                    ->label('Date')
                    ->required(),
                Forms\Components\TextInput::make('description')
                    ->label('Description'),
                Forms\Components\Select::make('type')
                    ->label('Type')
                    ->options([
                        TransactionType::Transfer->value => TransactionType::Transfer->getLabel(),
                    ])
                    ->disabled()
                    ->dehydrated()
                    ->required(),
                Forms\Components\TextInput::make('amount')
                    ->label('Amount')
                    ->money(static fn(Forms\Get $get) => BankAccount::find($get('bank_account_id'))?->account?->currency_code ?? CurrencyAccessor::getDefaultCurrency())
                    ->required(),
                Forms\Components\Select::make('bank_account_id')
                    ->label('From account')
                    ->options(fn(Forms\Get $get, ?Transaction $transaction) => Transaction::getBankAccountOptions(excludedAccountId: $get('account_id'), currentBankAccountId: $transaction?->bank_account_id))
                    ->live()
                    ->searchable()
                    ->required(),
                Forms\Components\Select::make('account_id')
                    ->label('To account')
                    ->live()
                    ->options(fn(Forms\Get $get, ?Transaction $transaction) => Transaction::getBankAccountAccountOptions(excludedBankAccountId: $get('bank_account_id'), currentAccountId: $transaction?->account_id))
                    ->searchable()
                    ->required(),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->autosize()
                    ->rows(10)
                    ->columnSpanFull(),
            ])
            ->columns();
    }

    public function journalTransactionForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Tabs')
                    ->contained(false)
                    ->tabs([
                        $this->getJournalTransactionFormEditTab(),
                        $this->getJournalTransactionFormNotesTab(),
                    ]),
            ])
            ->columns(1);
    }

    protected function getJournalTransactionFormEditTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Edit')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->schema([
                $this->getTransactionDetailsGrid(),
                $this->getJournalEntriesTableRepeater(),
            ]);
    }

    protected function getJournalTransactionFormNotesTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Notes')
            ->label('Notes')
            ->icon('heroicon-o-clipboard')
            ->id('notes')
            ->schema([
                $this->getTransactionDetailsGrid(),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->rows(10)
                    ->autosize(),
            ]);
    }

    protected function getTransactionDetailsGrid(): Forms\Components\Grid
    {
        return Forms\Components\Grid::make(6)
            ->schema([
                Forms\Components\DatePicker::make('posted_at')
                    ->label('Date')
                    ->softRequired(),
                Forms\Components\TextInput::make('description')
                    ->label('Description')
                    ->columnSpan(2),
                Forms\Components\TextInput::make('transaction_code')
                    ->columnSpan(2),
            ]);
    }

    protected function getJournalEntriesTableRepeater(): CustomTableRepeater
    {
        return CustomTableRepeater::make('journalEntries')
            ->relationship('journalEntries')
            ->hiddenLabel()
            ->columns(4)
            ->headers($this->getJournalEntriesTableRepeaterHeaders())
            ->schema($this->getJournalEntriesTableRepeaterSchema())
            ->deletable(fn(CustomTableRepeater $repeater) => $repeater->getItemsCount() > 2)
            ->deleteAction(function (Forms\Components\Actions\Action $action) {
                return $action
                    ->action(function (array $arguments, CustomTableRepeater $component): void {
                        $items = $component->getState();

                        $amount = $items[$arguments['item']]['amount'];
                        $type = $items[$arguments['item']]['type'];

                        $this->updateJournalEntryAmount(JournalEntryType::parse($type), '0.00', $amount);

                        unset($items[$arguments['item']]);

                        $component->state($items);

                        $component->callAfterStateUpdated();
                    });
            })
            ->rules([
                function () {
                    return function (string $attribute, $value, \Closure $fail) {
                        if (empty($value) || ! is_array($value)) {
                            $fail('Journal entries are required.');

                            return;
                        }

                        $hasDebit = false;
                        $hasCredit = false;
                        $totalDebits = 0;
                        $totalCredits = 0;

                        foreach ($value as $entry) {
                            if (! isset($entry['type']) || ! isset($entry['amount'])) {
                                continue;
                            }

                            $entryType = JournalEntryType::parse($entry['type']);
                            $amount = CurrencyConverter::convertToCents($entry['amount'], 'USD');

                            if ($entryType->isDebit()) {
                                $hasDebit = true;
                                $totalDebits += $amount;
                            } elseif ($entryType->isCredit()) {
                                $hasCredit = true;
                                $totalCredits += $amount;
                            }
                        }

                        if (! $hasDebit) {
                            $fail('At least one debit entry is required.');
                        }

                        if (! $hasCredit) {
                            $fail('At least one credit entry is required.');
                        }

                        if ($totalDebits !== $totalCredits) {
                            $debitFormatted = CurrencyConverter::formatCentsToMoney($totalDebits, CurrencyAccessor::getDefaultCurrency());
                            $creditFormatted = CurrencyConverter::formatCentsToMoney($totalCredits, CurrencyAccessor::getDefaultCurrency());
                            $fail("Total debits ({$debitFormatted}) must equal total credits ({$creditFormatted}).");
                        }
                    };
                },
            ])
            ->minItems(2)
            ->defaultItems(2)
            ->addable(false)
            ->footerItem(fn(): View => $this->getJournalTransactionModalFooter())
            ->extraActions([
                $this->buildAddJournalEntryAction(JournalEntryType::Debit),
                $this->buildAddJournalEntryAction(JournalEntryType::Credit),
            ]);
    }

    protected function getJournalEntriesTableRepeaterHeaders(): array
    {
        return [
            Header::make('type')
                ->width('150px')
                ->label('Type'),
            Header::make('description')
                ->width('320px')
                ->label('Description'),
            Header::make('account_id')
                ->width('320px')
                ->label('Account'),
            Header::make('amount')
                ->width('192px')
                ->label('Amount'),
        ];
    }

    protected function getJournalEntriesTableRepeaterSchema(): array
    {
        return [
            Forms\Components\Select::make('type')
                ->label('Type')
                ->options(JournalEntryType::class)
                ->live()
                ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set, $state, $old) {
                    $this->adjustJournalEntryAmountsForTypeChange(JournalEntryType::parse($state), JournalEntryType::parse($old), $get('amount'));
                })
                ->softRequired(),
            Forms\Components\TextInput::make('description')
                ->label('Description'),
            Forms\Components\Select::make('account_id')
                ->label('Account')
                ->options(fn(?JournalEntry $journalEntry): array => Transaction::getJournalAccountOptions(currentAccountId: $journalEntry?->account_id))
                ->softRequired()
                ->searchable(),
            Forms\Components\TextInput::make('amount')
                ->label('Amount')
                ->live(onBlur: true)
                ->money()
                ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set, ?string $state, ?string $old) {
                    $this->updateJournalEntryAmount(JournalEntryType::parse($get('type')), $state, $old);
                })
                ->softRequired(),
        ];
    }

    protected function buildAddJournalEntryAction(JournalEntryType $type): FormAction
    {
        $typeLabel = $type->getLabel();

        return FormAction::make("add{$typeLabel}Entry")
            ->button()
            ->outlined()
            ->color($type->isDebit() ? 'primary' : 'gray')
            ->action(function (CustomTableRepeater $component) use ($type) {
                $state = $component->getState();
                $newUuid = (string) Str::uuid();
                $state[$newUuid] = $this->defaultEntry($type);

                $component->state($state);
            });
    }

    public function getJournalTransactionModalFooter(): View
    {
        return view(
            'filament.company.components.actions.journal-entry-footer',
            [
                'debitAmount' => $this->getFormattedDebitAmount(),
                'creditAmount' => $this->getFormattedCreditAmount(),
                'difference' => $this->getFormattedBalanceDifference(),
                'isJournalBalanced' => $this->isJournalEntryBalanced(),
            ],
        );
    }
}
