<?php

namespace App\Filament\Company\Resources\Sales\InvoiceResource\Pages;

use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Accounting\PaymentMethod;
use App\Filament\Company\Resources\Banking\AccountResource;
use App\Filament\Company\Resources\Sales\InvoiceResource;
use App\Filament\Tables\Columns\CustomTextInputColumn;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\Transaction;
use App\Models\Banking\BankAccount;
use App\Models\Common\Client;
use App\Models\Setting\Currency;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * @property Form $form
 */
class RecordPayments extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected static string $view = 'filament.company.resources.sales.invoice-resource.pages.record-payments';

    public array $paymentAmounts = [];

    public ?array $data = [];

    public ?int $allocationAmount = null;

    #[Url(except: '')]
    public string $invoiceId = '';

    public function getBreadcrumb(): ?string
    {
        return 'Record Payments';
    }

    public function getTitle(): string | Htmlable
    {
        return 'Record Payments';
    }

    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return 'max-w-8xl';
    }

    public function mount(): void
    {
        parent::mount();

        if (!BankAccount::where('enabled', true)->first()) {
            Notification::make()
                ->warning()
                ->title('Default Bank Account Not Set')
                ->body('Please configure a default bank account before continuing.')
                ->send();

            $this->redirect(AccountResource::getUrl('index'));
        }

        $preservedClientId = $this->tableFilters['client_id']['value'] ?? null;
        $preservedCurrencyCode = $this->tableFilters['currency_code']['value'] ?? CurrencyAccessor::getDefaultCurrency();

        $this->tableFilters = [
            'client_id' => $preservedClientId ? ['value' => $preservedClientId] : [],
            'currency_code' => ['value' => $preservedCurrencyCode],
        ];

        if ($invoiceId = (int) $this->invoiceId) {
            $invoice = Invoice::find($invoiceId);

            if ($invoice) {
                if (! $preservedClientId) {
                    $this->tableFilters['client_id'] = ['value' => $invoice->client_id];
                } elseif ($invoice->client_id != $preservedClientId) {
                    $invoice = null;
                }
            }

            if ($invoice) {
                $this->paymentAmounts[$invoiceId] = $invoice->amount_due;
            }
        }

        $this->form->fill();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('processPayments')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Confirm payments')
                ->modalDescription(function () {
                    $invoiceCount = collect($this->paymentAmounts)->filter(fn($amount) => $amount > 0)->count();
                    $totalAmount = array_sum($this->paymentAmounts);
                    $currencyCode = $this->getTableFilterState('currency_code')['value'];
                    $totalFormatted = CurrencyConverter::formatCentsToMoney($totalAmount, $currencyCode, true);

                    return "You are about to pay {$invoiceCount} " . Str::plural('invoice', $invoiceCount) . " for a total of {$totalFormatted}. This action cannot be undone.";
                })
                ->action(function () {
                    $data = $this->data;
                    $tableRecords = $this->getTableRecords();
                    $paidCount = 0;
                    $totalPaid = 0;

                    /** @var Invoice $invoice */
                    foreach ($tableRecords as $invoice) {
                        if (! $invoice->canRecordPayment()) {
                            continue;
                        }

                        // Get the payment amount from our component state
                        $paymentAmount = $this->getPaymentAmount($invoice);

                        if ($paymentAmount <= 0) {
                            continue;
                        }

                        $paymentData = [
                            'transaction_code'  => $data['transaction_code'],
                            'posted_at'         => $data['posted_at'],
                            'payment_method'    => $data['payment_method'],
                            'bank_account_id'   => $data['bank_account_id'],
                            'amount'            => $paymentAmount,
                        ];

                        $invoice->recordPayment($paymentData);
                        $paidCount++;
                        $totalPaid += $paymentAmount;
                    }

                    $currencyCode = $this->getTableFilterState('currency_code')['value'];
                    $totalFormatted = CurrencyConverter::formatCentsToMoney($totalPaid, $currencyCode, true);

                    Notification::make()
                        ->title('Payments recorded successfully')
                        ->body("Recorded {$paidCount} " . Str::plural('payment', $paidCount) . " for a total of {$totalFormatted}")
                        ->success()
                        ->send();

                    $this->reset('paymentAmounts', 'allocationAmount');

                    $this->resetTable();
                }),
        ];
    }

    protected function allocateOldestFirst(Collection $invoices, int $amountInCents): void
    {
        $remainingAmount = $amountInCents;

        $sortedInvoices = $invoices->sortBy('due_date');

        foreach ($sortedInvoices as $invoice) {
            if ($remainingAmount <= 0) {
                break;
            }

            $amountDue = $invoice->amount_due;
            $allocation = min($remainingAmount, $amountDue);

            $this->paymentAmounts[$invoice->id] = $allocation;
            $remainingAmount -= $allocation;
        }
    }

    protected function hasSelectedClient(): bool
    {
        return ! empty($this->getTableFilterState('client_id')['value']);
    }

    /**
     * @return array<int | string, string | Form>
     */
    protected function getForms(): array
    {
        return [
            'form',
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->live()
            ->columns(2)
            ->schema([
                Forms\Components\TextInput::make('transaction_code')
                    ->default(fn() => Transaction::getNextDocumentNumber()),
                Forms\Components\Grid::make(3) // Changed from 3 to 4
                    ->schema([
                        Forms\Components\Select::make('bank_account_id')
                            ->label('Account')
                            ->options(static function () {
                                return Transaction::getBankAccountOptionsFlat();
                            })
                            ->default(fn() => BankAccount::where('enabled', true)->first()?->id)
                            ->selectablePlaceholder(false)
                            ->searchable()
                            ->softRequired(),
                        Forms\Components\DatePicker::make('posted_at')
                            ->label('Date')
                            ->default(company_today()->toDateString())
                            ->softRequired(),
                        Forms\Components\Select::make('payment_method')
                            ->label('Payment method')
                            ->selectablePlaceholder(false)
                            ->options(PaymentMethod::class)
                            ->default(PaymentMethod::BankPayment)
                            ->softRequired(),
                        // Forms\Components\TextInput::make('allocation_amount')
                        //     ->label('Allocate Payment Amount')
                        //     ->default(array_sum($this->paymentAmounts))
                        //     ->money($this->getTableFilterState('currency_code')['value'])
                        //     ->extraAlpineAttributes([
                        //         'x-on:keydown.enter.prevent' => '$refs.allocate.click()',
                        //     ])
                            // ->suffixAction(
                            //     Forms\Components\Actions\Action::make('allocate')
                            //         ->icon('heroicon-m-calculator')
                            //         ->extraAttributes([
                            //             'x-ref' => 'allocate',
                            //         ])
                            //         ->action(function ($state) {
                            //             $this->allocationAmount = CurrencyConverter::convertToCents($state, 'USD');
                            //             if ($this->allocationAmount && $this->hasSelectedClient()) {
                            //                 $this->allocateOldestFirst($this->getTableRecords(), $this->allocationAmount);
                            //             }
                            //         }),
                            // ),
                    ]),
            ])->statePath('data');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Invoice::query()
                    ->with(['client'])
                    ->unpaid()
            )
            ->recordClasses(['is-spreadsheet'])
            ->defaultSort('due_date')
            ->paginated(false)
            ->emptyStateHeading(fn () => $this->hasTableSearch()
                ? 'No invoices found'
                : 'No client selected')
            ->emptyStateDescription(fn () => $this->hasTableSearch()
                ? 'Try adjusting your search or filters.'
                : 'Select a client from the filters, or search by invoice number or client name to find unpaid invoices.')
            ->columns([
                TextColumn::make('client.name')
                    ->label('Client')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('invoice_number')
                    ->label('Invoice number')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('due_date')
                    ->label('Due date')
                    ->defaultDateFormat()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('amount_due')
                    ->label('Amount due')
                    ->currency(static fn(Invoice $record) => $record->currency_code)
                    ->alignEnd()
                    ->sortable()
                    ->summarize([
                        Summarizer::make()
                            ->using(function (Builder $query) {
                                $totalAmountDue = $query->sum('amount_due');
                                $bankAccountCurrency = $this->getSelectedBankAccount()->account->currency_code;
                                $activeCurrency = $this->getTableFilterState('currency_code')['value'] ?? $bankAccountCurrency;

                                if ($activeCurrency !== $bankAccountCurrency) {
                                    $totalAmountDue = CurrencyConverter::convertBalance($totalAmountDue, $activeCurrency, $bankAccountCurrency);
                                }

                                return CurrencyConverter::formatCentsToMoney($totalAmountDue, $bankAccountCurrency, true);
                            }),
                        Summarizer::make()
                            ->using(function (Builder $query) {
                                $totalAmountDue = $query->sum('amount_due');
                                $currencyCode = $this->getTableFilterState('currency_code')['value'];

                                return CurrencyConverter::formatCentsToMoney($totalAmountDue, $currencyCode, true);
                            })
                            ->visible(function () {
                                $activeCurrency = $this->getTableFilterState('currency_code')['value'] ?? null;
                                $bankAccountCurrency = $this->getSelectedBankAccount()->account->currency_code;

                                return $activeCurrency && $activeCurrency !== $bankAccountCurrency;
                            }),
                    ]),
                Tables\Columns\IconColumn::make('applyFullAmountAction')
                    ->icon('heroicon-m-chevron-double-right')
                    ->color('primary')
                    ->label('')
                    ->default('')
                    ->alignCenter()
                    ->width('3rem')
                    ->tooltip('Apply full amount')
                    ->action(
                        Tables\Actions\Action::make('applyFullPayment')
                            ->action(function (Invoice $record) {
                                $this->paymentAmounts[$record->id] = $record->amount_due;
                            }),
                    ),
                CustomTextInputColumn::make('payment_amount')
                    ->label('Payment amount')
                    ->alignEnd()
                    ->navigable()
                    ->mask(RawJs::make('$money($input)'))
                    ->updateStateUsing(function (Invoice $record, $state) {
                        if (! CurrencyConverter::isValidAmount($state, 'USD')) {
                            $this->paymentAmounts[$record->id] = 0;

                            return '0.00';
                        }

                        $paymentCents = CurrencyConverter::convertToCents($state, 'USD');

                        if ($paymentCents > $record->amount_due) {
                            $paymentCents = $record->amount_due;
                        }

                        $this->paymentAmounts[$record->id] = $paymentCents;

                        return $state;
                    })
                    ->getStateUsing(function (Invoice $record) {
                        $paymentAmount = $this->paymentAmounts[$record->id] ?? 0;

                        return CurrencyConverter::convertCentsToFormatSimple($paymentAmount, 'USD');
                    })
                    ->summarize([
                        Summarizer::make()
                            ->using(function () {
                                $total = array_sum($this->paymentAmounts);
                                $bankAccountCurrency = $this->getSelectedBankAccount()->account->currency_code;
                                $activeCurrency = $this->getTableFilterState('currency_code')['value'] ?? $bankAccountCurrency;

                                if ($activeCurrency !== $bankAccountCurrency) {
                                    $total = CurrencyConverter::convertBalance($total, $activeCurrency, $bankAccountCurrency);
                                }

                                return CurrencyConverter::formatCentsToMoney($total, $bankAccountCurrency, true);
                            }),
                        Summarizer::make()
                            ->using(fn() => $this->totalPaymentAmount)
                            ->visible(function () {
                                $activeCurrency = $this->getTableFilterState('currency_code')['value'] ?? null;
                                $bankAccountCurrency = $this->getSelectedBankAccount()->account->currency_code;

                                return $activeCurrency && $activeCurrency !== $bankAccountCurrency;
                            }),
                    ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('applyFullAmounts')
                    ->label('Apply full amounts')
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $records->each(function (Invoice $invoice) {
                            $this->paymentAmounts[$invoice->id] = $invoice->amount_due;
                        });
                    }),
                Tables\Actions\BulkAction::make('clearAmounts')
                    ->label('Clear amounts')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $records->each(function (Invoice $invoice) {
                            $this->paymentAmounts[$invoice->id] = 0;
                        });
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('currency_code')
                    ->label('Currency')
                    ->selectablePlaceholder(false)
                    ->default(CurrencyAccessor::getDefaultCurrency())
                    ->options(Currency::query()->pluck('name', 'code')->toArray())
                    ->searchable()
                    ->resetState([
                        'value' => CurrencyAccessor::getDefaultCurrency(),
                    ])
                    ->indicateUsing(function (Tables\Filters\SelectFilter $filter, array $state) {
                        if (blank($state['value'] ?? null)) {
                            return [];
                        }

                        $label = collect($filter->getOptions())
                            ->mapWithKeys(fn(string | array $label, string $value): array => is_array($label) ? $label : [$value => $label])
                            ->get($state['value']);

                        if (blank($label)) {
                            return [];
                        }

                        $indicator = $filter->getLabel();

                        return Tables\Filters\Indicator::make("{$indicator}: {$label}")->removable(false);
                    }),
                Tables\Filters\SelectFilter::make('client_id')
                    ->label('Client')
                    ->placeholder('All clients')
                    ->options(fn() => Client::query()->pluck('name', 'id')->toArray())
                    ->searchable()
                    ->query(function (EloquentBuilder $query, array $data) {
                        if (filled($data['value'] ?? null)) {
                            return $query->where('client_id', $data['value']);
                        }

                        if ($this->hasTableSearch()) {
                            return $query;
                        }

                        return $query->whereRaw('1 = 0');
                    }),
                Tables\Filters\SelectFilter::make('status')
                    ->multiple()
                    ->options(InvoiceStatus::getUnpaidOptions()),
            ]);
    }

    protected function getPaymentAmount(Invoice $record): int
    {
        return $this->paymentAmounts[$record->id] ?? 0;
    }

    #[Computed]
    public function totalPaymentAmount(): string
    {
        $total = array_sum($this->paymentAmounts);

        $currencyCode = $this->getTableFilterState('currency_code')['value'];

        return CurrencyConverter::formatCentsToMoney($total, $currencyCode, true);
    }

    public function getSelectedBankAccount(): BankAccount
    {
        $bankAccountId = $this->data['bank_account_id'];

        $bankAccount = BankAccount::find($bankAccountId);

        return $bankAccount ?: BankAccount::where('enabled', true)->first();
    }

    public function resetTableFiltersForm(): void
    {
        parent::resetTableFiltersForm();

        $this->invoiceId = '';
        $this->paymentAmounts = [];
        $this->allocationAmount = null;
    }

    public function removeTableFilters(): void
    {
        parent::removeTableFilters();

        $this->invoiceId = '';
        $this->paymentAmounts = [];
        $this->allocationAmount = null;
    }

    public function updatedTableSearch(): void
    {
        if ($this->getTable()->persistsSearchInSession()) {
            session()->put(
                $this->getTableSearchSessionKey(),
                $this->tableSearch,
            );
        }

        if ($this->getTable()->shouldDeselectAllRecordsWhenFiltered()) {
            $this->deselectAllTableRecords();
        }

        $this->resetPage();
        $this->prunePaymentAmountsToVisibleRecords();
    }

    protected function handleTableFilterUpdates(): void
    {
        parent::handleTableFilterUpdates();

        $this->prunePaymentAmountsToVisibleRecords();
    }

    protected function prunePaymentAmountsToVisibleRecords(): void
    {
        $visibleInvoiceIds = $this->getTableRecords()->pluck('id')->toArray();
        $visibleInvoiceKeys = array_flip($visibleInvoiceIds);

        $this->paymentAmounts = array_intersect_key($this->paymentAmounts, $visibleInvoiceKeys);
        $this->allocationAmount = null;
    }
}
