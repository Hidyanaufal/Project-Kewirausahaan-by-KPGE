<?php

namespace App\Models\Accounting;

use App\Casts\RateCast;
use App\Collections\Accounting\DocumentCollection;
use App\Concerns\HasAttacments;
use App\Concerns\HasDocumentNumber;
use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AdjustmentCategory;
use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\DocumentType;
use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Accounting\JournalEntryType;
use App\Enums\Accounting\TransactionType;
use App\Filament\Company\Resources\Sales\InvoiceResource;
use App\Helpers\AuthHelper;
use App\Jobs\SendInvoiceApprovedNotification;
use App\Models\Addons\Project;
use App\Models\Banking\BankAccount;
use App\Models\Common\Client;
use App\Models\Company;
use App\Models\Setting\DocumentDefault;
use App\Observers\InvoiceObserver;
use App\Observers\TransactionObserver;
use App\Scopes\CurrentCompanyScope;
use App\Services\TransactionService;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use Filament\Actions\Action;
use Filament\Actions\MountableAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\StaticAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Component;

#[CollectedBy(DocumentCollection::class)]
#[ObservedBy(InvoiceObserver::class)]
class Invoice extends Document
{
    use HasAttacments;
    use HasDocumentNumber;

    protected $table = 'invoices';

    protected $fillable = [
        'company_id',
        'client_id',
        'recipient_details',
        'estimate_id',
        'recurring_invoice_id',
        'logo',
        'header',
        'subheader',
        'invoice_number',
        'order_number',
        'date',
        'due_date',
        'approved_at',
        'paid_at',
        'last_sent_at',
        'last_viewed_at',
        'status',
        'currency_code',
        'discount_method',
        'discount_computation',
        'discount_rate',
        'subtotal',
        'tax_total',
        'discount_total',
        'total',
        'amount_paid',
        'terms',
        'footer',
        'created_by',
        'updated_by',
        'project_id',
        'tax_code_id',
        'tax_number',
        'tax_date',
        'profit_computation',
        'profit_rate',
        'profit_total',
        'contract_number',
        'po_reference_number',
        'po_date',
        'spk_number',
        'spk_date',
        'pph23',
        'hide_offering_items',
        'finalized_at',
    ];

    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'status' => InvoiceStatus::class,
        'discount_method' => DocumentDiscountMethod::class,
        'discount_computation' => AdjustmentComputation::class,
        'discount_rate' => RateCast::class,
        'profit_computation' => AdjustmentComputation::class,
        'profit_rate' => RateCast::class,
        'hide_offering_items' => 'boolean',
        'finalized_at' => 'datetime',
        'is_imported' => 'boolean',
    ];

    protected $appends = [
        'logo_url',
    ];

    public function getNumberColumn(): string
    {
        return 'invoice_number';
    }

    public function getDocumentPrefix(): string
    {
        $company ??= auth()->user()?->currentCompany;
        if (! $company) {
            throw new \RuntimeException('No current company is set for the user.');
        }
        $defaultSetting = $company->defaultInvoice;
        $numberPrefix = $defaultSetting->number_prefix ?? '';

        return $numberPrefix;
    }

    protected static function boot()
    {
        parent::boot();
        static::updating(function (Invoice $record) {
            $subtotal = $record->subtotal + $record->profit_total - ($record->discount_total ?? 0);

            if ($record->pph23 !== null) {
                if ($record->isWapu()) {
                    $record->actual_total = $subtotal - (int) $record->pph23;
                } else {
                    $record->actual_total = $subtotal - (int) $record->pph23 + $record->tax_total;
                }
            } else {
                $tax_pph23 = $subtotal * 0.02;
                if ($record->isWapu()) {
                    $record->actual_total = $subtotal - $tax_pph23;
                } else {
                    $record->actual_total = $subtotal - $tax_pph23 + $record->tax_total;
                }
            }

            if ($record->isDirty('status') && $record->status === InvoiceStatus::Paid) {
                $latestTransaction = Transaction::query()
                    ->where([
                        'transactionable_type' => Invoice::class,
                        'transactionable_id' => $record->id,
                        'is_payment' => true,
                    ])
                    ->orderByDesc('id')
                    ->first();

                if ($latestTransaction) {
                    $record->applyPaymentJournalEntriesForTransaction($latestTransaction);
                }
            }

            if ($record->status === InvoiceStatus::Cancelled) {
                return;
            }

            $record->mergeTaxAndRevenueJournalEntries();
        });
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(static function (mixed $value, array $attributes): ?string {
            return $attributes['logo'] ? Storage::disk('public')->url($attributes['logo']) : null;
        });
    }

    public function exceedsWapuThreshold(): bool
    {
        return $this->total > 1_000_000_000; // Greater than 10jt
    }

    public function isWapu(): bool
    {
        return $this->exceedsWapuThreshold()
            && Adjustment::companyHasPpnWapu($this->company_id);
    }

    public function salesTaxAccountId(): int
    {
        if ($this->isWapu()) {
            $wapuAccountId = $this->historicalWapuTaxAccountId();
            if ($wapuAccountId) {
                return $wapuAccountId;
            }
        }

        $ppnAccountId = $this->historicalPpnTaxAccountId();
        if ($ppnAccountId) {
            return $ppnAccountId;
        }

        return $this->isWapu()
            ? Account::getAccountsWapu($this->company_id)->id
            : Account::getAccountsPPn($this->company_id)->id;
    }

    public function mergeTaxAndRevenueJournalEntries(): void
    {
        if ($this->status === InvoiceStatus::Cancelled) {
            return;
        }

        DB::transaction(function () {
            $pph23Account = Account::getAccountsPPh23($this->company_id);
            $ppnAccount = Account::getAccountsPPn($this->company_id);

            $taxAccounts = [
                $pph23Account->id,
                $ppnAccount->id,
            ];

            if (Adjustment::companyHasPpnWapu($this->company_id)) {
                $taxAccounts[] = Account::getAccountsWapu($this->company_id)->id;
            }

            $transactions = Transaction::where('transactionable_type', Invoice::class)
                ->where('transactionable_id', $this->id)
                ->get();

            foreach ($transactions as $trx) {
                $journals = JournalEntry::where('transaction_id', $trx->id)->get();

                if ($journals->isEmpty()) {
                    continue;
                }

                $taxJournals = $journals->whereIn('account_id', $taxAccounts);
                if ($taxJournals->isNotEmpty()) {
                    $mergedTax = $taxJournals
                        ->groupBy(['account_id', 'type'])
                        ->map(function ($byType, $accountId) use ($trx, $pph23Account) {
                            return collect($byType)->map(function ($group, $type) use ($accountId, $trx, $pph23Account) {
                                if ($accountId == $pph23Account->id && $this->pph23) {
                                    return [
                                        'company_id' => $trx->company_id,
                                        'account_id' => $accountId,
                                        'transaction_id' => $trx->id,
                                        'type' => $type,
                                        'amount' => $this->pph23,
                                        'description' => "Tax entry ({$group->first()->account->name}) for Invoice #{$this->invoice_number}",
                                        'created_by' => $this->updated_by ?? 1,
                                        'updated_by' => $this->updated_by ?? 1,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ];
                                }

                                return [
                                    'company_id' => $trx->company_id,
                                    'account_id' => $accountId,
                                    'transaction_id' => $trx->id,
                                    'type' => $type,
                                    'amount' => $group->sum('amount'),
                                    'description' => "Tax entry ({$group->first()->account->name}) for Invoice #{$this->invoice_number}",
                                    'created_by' => $this->updated_by ?? 1,
                                    'updated_by' => $this->updated_by ?? 1,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];
                            });
                        })
                        ->flatten(1)
                        ->values();

                    JournalEntry::where('transaction_id', $trx->id)
                        ->whereIn('account_id', $taxAccounts)
                        ->delete();

                    if ($mergedTax->isNotEmpty()) {
                        JournalEntry::insert($mergedTax->toArray());
                    }
                }

                $revenueAccounts = Account::where('company_id', $trx->company_id)
                    ->where('category', AccountCategory::Revenue)
                    ->pluck('id');

                $revenueJournals = $journals->whereIn('account_id', $revenueAccounts);

                if ($revenueJournals->isNotEmpty()) {
                    $mergedRevenue = $revenueJournals
                        ->groupBy('type')
                        ->map(function ($group, $type) use ($trx) {
                            $first = $group->first();

                            return [
                                'company_id' => $trx->company_id,
                                'account_id' => $first->account_id,
                                'transaction_id' => $trx->id,
                                'type' => $type,
                                'amount' => $group->sum('amount'),
                                'description' => "Merged revenue entry ({$first->account->name}) for Invoice #{$this->invoice_number}",
                                'created_by' => $this->updated_by ?? 1,
                                'updated_by' => $this->updated_by ?? 1,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        })
                        ->values();

                    JournalEntry::where('transaction_id', $trx->id)
                        ->whereIn('account_id', $revenueAccounts)
                        ->delete();

                    JournalEntry::insert($mergedRevenue->toArray());
                }
            }
        });
    }

    public function applyPaymentJournalEntriesForTransaction(Transaction $paymentTransaction, bool $preferCalculatedCash = false): void
    {
        $wapuAccountId = $this->historicalWapuTaxAccountId();
        $accountWapu = ($this->isWapu() && $wapuAccountId)
            ? Account::query()->withoutGlobalScopes()->find($wapuAccountId)
            : null;
        $ppnAccountId = $this->historicalPpnTaxAccountId();
        $accountPPn = $ppnAccountId
            ? Account::query()->withoutGlobalScopes()->find($ppnAccountId)
            : Account::getAccountsPPn($this->company_id);
        $accountPPh23 = Account::getAccountsPPh23($this->company_id);
        $receivableAccount = Account::getAccountsReceivableAccount($this->company_id);

        $approvalTransaction = Transaction::query()
            ->where([
                'is_payment' => false,
                'transactionable_type' => Invoice::class,
                'transactionable_id' => $this->id,
            ])
            ->first();

        if (! $approvalTransaction) {
            return;
        }

        $approvalJournalEntries = JournalEntry::query()
            ->where('transaction_id', $approvalTransaction->id)
            ->get();

        $journalReceivable = $approvalJournalEntries
            ->where('account_id', $receivableAccount->id)
            ->first();

        if (! $journalReceivable) {
            return;
        }

        $totalReceivable = (int) $journalReceivable->amount;

        $pph23Amount = (int) (
            $paymentTransaction->meta['pph23_cents']
            ?? $this->pph23
            ?? 0
        );

        $previousPaymentTransactionIds = Transaction::query()
            ->where([
                'transactionable_type' => Invoice::class,
                'transactionable_id' => $this->id,
                'is_payment' => true,
            ])
            ->where('id', '<', $paymentTransaction->id)
            ->pluck('id');

        $previousReceivableCredits = (int) JournalEntry::query()
            ->whereIn('transaction_id', $previousPaymentTransactionIds)
            ->where('account_id', $receivableAccount->id)
            ->where('type', JournalEntryType::Credit)
            ->sum('amount');

        $previousReceivableDebits = (int) JournalEntry::query()
            ->whereIn('transaction_id', $previousPaymentTransactionIds)
            ->where('account_id', $receivableAccount->id)
            ->where('type', JournalEntryType::Debit)
            ->sum('amount');

        $previousNetReceivable = $previousReceivableCredits - $previousReceivableDebits;
        $remainingReceivable = $totalReceivable - $previousNetReceivable;

        $taxAccountIds = $this->historicalPaymentTaxAccountIds();

        JournalEntry::query()
            ->where('transaction_id', $paymentTransaction->id)
            ->where(function ($query) use ($taxAccountIds, $receivableAccount, $accountPPh23, $paymentTransaction) {
                $query->whereIn('account_id', $taxAccountIds)
                    ->orWhere('account_id', $receivableAccount->id)
                    ->orWhere('account_id', $accountPPh23->id)
                    ->orWhere('account_id', $paymentTransaction->bankAccount?->account_id);
            })
            ->delete();

        $journalAmountTotal = 0;
        $paymentDescription = "Invoice #{$this->invoice_number}: Payment from {$this->client->name}";

        foreach ($approvalJournalEntries as $journal) {
            // Non-WAPU invoice payments already collect cash inclusive of PPN,
            // so only WAPU tax should be reclassified at payment time.
            if ($this->isWapu() && $accountWapu && $journal->account_id == $accountWapu->id) {
                $entry = JournalEntry::create([
                    'transaction_id' => $paymentTransaction->id,
                    'account_id' => $accountWapu->id,
                    'type' => JournalEntryType::Debit,
                    'amount' => $journal->amount,
                    'description' => $paymentDescription,
                ]);
                $journalAmountTotal += $entry->amount;
            }
        }

        if ($pph23Amount > 0) {
            $pph23Entry = JournalEntry::create([
                'transaction_id' => $paymentTransaction->id,
                'account_id' => $accountPPh23->id,
                'type' => JournalEntryType::Debit,
                'amount' => $pph23Amount,
                'description' => $paymentDescription,
            ]);
            $journalAmountTotal += $pph23Entry->amount;
        }

        $calculatedBankAmount = $remainingReceivable - $journalAmountTotal;
        $cashAmount = (int) (
            $preferCalculatedCash
                ? $calculatedBankAmount
                : ($paymentTransaction->meta['amount_in_document_currency_cents'] ?? $calculatedBankAmount)
        );

        $bankAccountId = $paymentTransaction->bankAccount?->account_id;
        if ($bankAccountId) {
            JournalEntry::create([
                'transaction_id' => $paymentTransaction->id,
                'account_id' => $bankAccountId,
                'type' => JournalEntryType::Debit,
                'amount' => $cashAmount,
                'description' => $paymentDescription,
            ]);
        }

        JournalEntry::create([
            'transaction_id' => $paymentTransaction->id,
            'account_id' => $journalReceivable->account_id,
            'type' => JournalEntryType::Credit,
            'amount' => $remainingReceivable,
            'description' => $paymentDescription,
        ]);
    }

    public function rebuildPaymentJournalEntries(?Transaction $paymentTransaction = null): void
    {
        $payments = $paymentTransaction
            ? collect([$paymentTransaction->load('bankAccount')])
            : $this->payments()->with('bankAccount')->orderBy('id')->get();

        foreach ($payments as $trx) {
            $this->applyPaymentJournalEntriesForTransaction($trx);
        }
    }

    public function expectedHistoricalPaymentTaxAccountId(): ?int
    {
        $taxAccountIds = $this->historicalPaymentTaxAccountIds();
        $hasHistoricalTaxEntries = false;

        if ($taxAccountIds !== []) {
            $approvalTransactionId = $this->approvalTransaction?->id;
            $paymentIds = $this->payments()->select('id');

            $hasHistoricalTaxEntries = JournalEntry::query()
                ->when($approvalTransactionId, function ($query) use ($approvalTransactionId, $paymentIds) {
                    $query->where('transaction_id', $approvalTransactionId)
                        ->orWhereIn('transaction_id', $paymentIds);
                }, fn ($query) => $query->whereIn('transaction_id', $paymentIds))
                ->whereIn('account_id', $taxAccountIds)
                ->exists();
        }

        if (! $hasHistoricalTaxEntries && (int) $this->tax_total <= 0) {
            return null;
        }

        if ($this->isWapu()) {
            return $this->historicalWapuTaxAccountId() ?? $this->historicalPpnTaxAccountId();
        }

        return $this->historicalPpnTaxAccountId();
    }

    public function historicalPaymentTaxAccountIds(): array
    {
        return array_values(array_unique(array_filter([
            $this->historicalPpnTaxAccountId(),
            $this->historicalWapuTaxAccountId(),
        ])));
    }

    public function historicalPpnTaxAccountId(): ?int
    {
        try {
            return Account::getAccountsPPn($this->company_id)->id;
        } catch (\Throwable) {
            return null;
        }
    }

    public function historicalWapuTaxAccountId(): ?int
    {
        $adjustmentAccountId = Adjustment::findPpnWapuForCompany($this->company_id)?->account_id;
        if ($adjustmentAccountId) {
            return (int) $adjustmentAccountId;
        }

        try {
            return Account::getAccountsWapuIfConfigured($this->company_id)?->id;
        } catch (\Throwable) {
            return null;
        }
    }

    public function needsHistoricalPaymentTaxAccountFix(): bool
    {
        $expectedTaxAccountId = $this->expectedHistoricalPaymentTaxAccountId();
        if (! $expectedTaxAccountId) {
            return false;
        }

        $taxAccountIds = $this->historicalPaymentTaxAccountIds();

        if ($taxAccountIds === []) {
            return false;
        }

        $taxEntriesByPayment = JournalEntry::query()
            ->whereIn('transaction_id', $this->payments()->select('id'))
            ->whereIn('account_id', $taxAccountIds)
            ->where('type', JournalEntryType::Debit)
            ->get(['transaction_id', 'account_id'])
            ->groupBy('transaction_id');

        return $taxEntriesByPayment->contains(function ($entries) use ($expectedTaxAccountId) {
            $taxAccountIds = $entries->pluck('account_id')->unique()->values();

            return $taxAccountIds->count() > 1
                || ($taxAccountIds->count() === 1 && (int) $taxAccountIds->first() !== $expectedTaxAccountId);
        });
    }

    public function journalTransactionIsBalanced(Transaction $transaction): bool
    {
        $debits = (int) JournalEntry::query()
            ->where('transaction_id', $transaction->id)
            ->where('type', JournalEntryType::Debit)
            ->sum('amount');

        $credits = (int) JournalEntry::query()
            ->where('transaction_id', $transaction->id)
            ->where('type', JournalEntryType::Credit)
            ->sum('amount');

        return $debits === $credits;
    }

    public function needsProfitJournalFix(): bool
    {
        if ($this->profit_total <= 0) {
            return false;
        }

        if (in_array($this->status, [InvoiceStatus::Draft, InvoiceStatus::Cancelled], true)) {
            return false;
        }

        $approvalTransaction = $this->approvalTransaction;
        if (! $approvalTransaction) {
            return false;
        }

        $receivableAccount = Account::getAccountsReceivableAccount($this->company_id);
        $arDebit = (int) JournalEntry::query()
            ->where('transaction_id', $approvalTransaction->id)
            ->where('account_id', $receivableAccount->id)
            ->where('type', JournalEntryType::Debit)
            ->sum('amount');

        $taxCredit = (int) JournalEntry::query()
            ->where('transaction_id', $approvalTransaction->id)
            ->where('account_id', $this->salesTaxAccountId())
            ->where('type', JournalEntryType::Credit)
            ->sum('amount');

        $expectedAr = $this->formatAmountToDefaultCurrency((int) $this->total);
        $expectedTax = $this->formatAmountToDefaultCurrency((int) $this->tax_total);

        return $arDebit !== $expectedAr
            || ($this->tax_total > 0 && $taxCredit !== $expectedTax);
    }

    public function fixProfitJournalEntries(): void
    {
        $this->refresh();
        $this->loadMissing(['lineItems.adjustments', 'lineItems.offering', 'client']);

        $this->updateApprovalTransaction();
        $this->mergeTaxAndRevenueJournalEntries();
        $this->rebuildPaymentJournalEntries();

        $transactions = Transaction::query()
            ->where('transactionable_type', Invoice::class)
            ->where('transactionable_id', $this->id)
            ->get();

        foreach ($transactions as $transaction) {
            if (! $this->journalTransactionIsBalanced($transaction)) {
                throw new \RuntimeException(
                    "Journal entries do not balance for transaction #{$transaction->id} on Invoice #{$this->invoice_number}."
                );
            }
        }
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id', 'id')->withoutGlobalScopes();
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }

    public function payments(): MorphMany
    {
        return $this->transactions()->where('is_payment', true);
    }

    public function deposits(): MorphMany
    {
        return $this->transactions()->where('type', TransactionType::Deposit)->where('is_payment', true);
    }

    public function withdrawals(): MorphMany
    {
        return $this->transactions()->where('type', TransactionType::Withdrawal)->where('is_payment', true);
    }

    public function approvalTransaction(): MorphOne
    {
        return $this->morphOne(Transaction::class, 'transactionable')
            ->where('type', TransactionType::Journal);
    }

    protected function sourceType(): Attribute
    {
        return Attribute::get(function () {
            return match (true) {
                $this->estimate_id !== null => DocumentType::Estimate,
                $this->recurring_invoice_id !== null => DocumentType::RecurringInvoice,
                default => null,
            };
        });
    }

    public static function documentType(): DocumentType
    {
        return DocumentType::Invoice;
    }

    public function documentNumber(): ?string
    {
        return $this->invoice_number;
    }

    public function documentDate(): ?string
    {
        return $this->date?->toDefaultDateFormat();
    }

    public function dueDate(): ?string
    {
        return $this->due_date?->toDefaultDateFormat();
    }

    public function referenceNumber(): ?string
    {
        return $this->order_number;
    }

    public function getAmountDueAttribute($value): int
    {
        if ($value !== null && $value !== '') {
            return (int) $value;
        }

        return (int) ($this->getAttribute('total') - $this->getAttribute('amount_paid'));
    }

    public function amountDue(): ?string
    {
        return $this->amount_due;
    }

    #[Scope]
    protected function unpaid(Builder $query): Builder
    {
        return $query->whereIn('status', InvoiceStatus::unpaidStatuses());
    }

    // TODO: Consider storing the numeric part of the invoice number separately
    #[Scope]
    protected function byNumber(Builder $query, string $number): Builder
    {
        $invoicePrefix = DocumentDefault::invoice()->first()->number_prefix ?? '';

        return $query->where(function ($q) use ($number, $invoicePrefix) {
            $q->where('invoice_number', $number)
                ->orWhere('invoice_number', $invoicePrefix . $number);
        });
    }

    #[Scope]
    protected function overdue(Builder $query): Builder
    {
        return $query
            ->unpaid()
            ->where('status', InvoiceStatus::Overdue);
    }

    public function shouldBeOverdue(): bool
    {
        return $this->due_date?->isBefore(company_today()) && $this->canBeOverdue();
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function wasApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function hasBeenSent(): bool
    {
        return $this->last_sent_at !== null;
    }

    public function hasBeenViewed(): bool
    {
        return $this->last_viewed_at !== null;
    }

    public function canRecordPayment(): bool
    {
        if (! $this->client_id) {
            return false;
        }

        // tidak ada skema dp: tidak boleh jika sudah punya 1 atau lebih payment
        if ($this->payments()->count() >= 1) {
            return true;
        }

        return ! in_array($this->status, [
            InvoiceStatus::Draft,
            InvoiceStatus::Paid,
            InvoiceStatus::Void,
            InvoiceStatus::Cancelled,
        ]);
    }

    /**
     * Whether payment records on this invoice may be created/edited from the UI.
     * Legacy imports skip finalize, so approved imported invoices are allowed.
     */
    public function canManagePayments(): bool
    {
        if ($this->status === InvoiceStatus::Cancelled) {
            return false;
        }

        if ($this->isFinalized()) {
            return true;
        }

        return $this->is_imported && $this->wasApproved();
    }

    public function canBulkRecordPayment(): bool
    {
        if (! $this->client_id || $this->currency_code !== CurrencyAccessor::getDefaultCurrency()) {
            return false;
        }

        if ($this->payments()->count() >= 1) {
            return false;
        }

        return ! in_array($this->status, [
            InvoiceStatus::Draft,
            InvoiceStatus::Paid,
            InvoiceStatus::Void,
            InvoiceStatus::Overpaid,
        ]);
    }

    public function canBeOverdue(): bool
    {
        return in_array($this->status, InvoiceStatus::canBeOverdue());
    }

    public function canBeApproved(): bool
    {
        return $this->status !== InvoiceStatus::Cancelled
            && ($this->isDraft() || $this->status === InvoiceStatus::Sent)
            && ! $this->wasApproved()
            && AuthHelper::isAdmin();
    }

    public function canBeFinalized(): bool
    {
        return $this->status !== InvoiceStatus::Cancelled
            && $this->wasApproved()
            && ! $this->isFinalized()
            && AuthHelper::isAdmin();
    }

    public function canBeMarkedAsSent(): bool
    {
        return $this->status !== InvoiceStatus::Cancelled
            && ! $this->hasBeenSent()
            && $this->wasApproved()
            && ! $this->isPaid();
    }

    public function hasPayments(): bool
    {
        return $this->payments()->exists();
    }

    public static function getNextDocumentNumber(?Company $company = null): string
    {
        $company ??= auth()->user()?->currentCompany;

        if (! $company) {
            throw new \RuntimeException('No current company is set for the user.');
        }

        $defaultInvoiceSettings = $company->defaultInvoice;
        $numberPrefix = $defaultInvoiceSettings->number_prefix ?? '';

        $companyCode = $company->company_code;
        $romanMonth = getRomanMonth(now()->month);
        $year = now()->year;
        $year2digit = now()->format('y');

        // Build prefix with year: e.g., "PG26"
        $prefixWithYear = $numberPrefix . $year2digit;
        $pattern = $prefixWithYear . '-%';

        // Query all documents from current year matching the pattern
        $documents = static::query()
            ->where('invoice_number', 'like', $pattern)
            ->get();

        // Extract sequence numbers from matching documents
        $sequences = $documents->map(function ($doc) use ($prefixWithYear) {
            // Match both 3 and 4 digit sequences for backward compatibility
            if (preg_match('/' . preg_quote($prefixWithYear) . '-(\d{3,4})/', $doc->invoice_number, $matches)) {
                return (int) $matches[1];
            }

            return 0;
        })->filter()->values();

        // Find maximum sequence number (handle edge cases where older documents might have higher sequences)
        $maxSequence = $sequences->isNotEmpty() ? $sequences->max() : 0;
        $nextSequence = str_pad($maxSequence + 1, 3, '0', STR_PAD_LEFT);

        // Format: PG26-392/KPG-I/2026
        $documentNumber = sprintf(
            '%s-%s/%s-%s/%d',
            $prefixWithYear,
            $nextSequence,
            $companyCode,
            $romanMonth,
            $year,
        );

        return $documentNumber;
    }

    public static function isInvoiceNumberTaken(string $invoiceNumber, ?Company $company = null, ?int $ignoreId = null): bool
    {
        $invoiceNumber = trim($invoiceNumber);
        $companyId = $company?->id ?? auth()->user()?->currentCompany?->id ?? AuthHelper::currentCompany();

        if ($invoiceNumber === '' || ! $companyId) {
            return false;
        }

        return static::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->where('company_id', $companyId)
            ->where('invoice_number', $invoiceNumber)
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * Base amount for PPh 23 (subtotal + profit - discount).
     */
    public function pph23Base(): int
    {
        return (int) $this->subtotal + (int) $this->profit_total - (int) ($this->discount_total ?? 0);
    }

    /**
     * Invoice total payable for a given PPh 23 amount (matches actual_total updating hook).
     */
    public function actualTotalForPph23(?int $pph23Cents): int
    {
        $subtotal = $this->pph23Base();
        $pph23 = $pph23Cents ?? (int) round($subtotal * 0.02);

        if ($this->isWapu()) {
            return $subtotal - $pph23;
        }

        return $subtotal - $pph23 + (int) $this->tax_total;
    }

    /**
     * Remaining cash due for a payment given a (possibly manual) PPh 23 amount.
     */
    public function paymentAmountForPph23(?int $pph23Cents): int
    {
        return max(
            $this->actualTotalForPph23($pph23Cents) - (int) ($this->amount_paid ?? 0),
            0
        );
    }

    /**
     * Prefer stored invoice.pph23 on the payment form (including explicit 0); fall back to 2% of base.
     */
    public function defaultPph23ForPaymentForm(): int
    {
        if ($this->pph23 !== null) {
            return (int) $this->pph23;
        }

        return (int) round($this->pph23Base() * 0.02);
    }

    /**
     * Parse form/API pph23: explicit 0 is valid; blank/missing stays null (auto 2% on create/update).
     */
    public static function parsePph23Cents(array $data): ?int
    {
        if (! array_key_exists('pph23', $data) || $data['pph23'] === null || $data['pph23'] === '') {
            return null;
        }

        return (int) $data['pph23'];
    }

    /**
     * Update pph23 then record payment, aligning cash amount when the payment is a full-pay intent.
     */
    public function recordPaymentWithPph23(array $data): void
    {
        $pph23Cents = static::parsePph23Cents($data);
        $amountCents = (int) $data['amount'];
        $previousAmountDue = (int) $this->amount_due;
        $projectedFullPay = $this->paymentAmountForPph23($pph23Cents);
        $currentActualTotalDue = (int) ($this->actual_total ?? $this->total) - (int) ($this->amount_paid ?? 0);

        $isFullPayIntent = $amountCents === $previousAmountDue
            || $amountCents === $projectedFullPay
            || $amountCents === $currentActualTotalDue;

        $this->update([
            'pph23' => $pph23Cents,
        ]);

        $this->refresh();

        if ($isFullPayIntent) {
            $amountCents = $this->paymentAmountForPph23($pph23Cents);
        }

        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        $this->recordPayment([
            ...$data,
            'amount' => $amountCents,
            'pph23' => $pph23Cents,
        ]);
    }

    /**
     * Update payment with PPh 23, aligning cash amount when the payment is a full-pay intent.
     */
    public function updatePaymentWithPph23(Transaction $payment, array $data): void
    {
        $pph23Cents = static::parsePph23Cents($data);
        $amountCents = (int) $data['amount'];
        $currentPaymentAmount = (int) ($payment->meta['amount_in_document_currency_cents'] ?? $payment->amount);
        $amountDueBeforeThisPayment = (int) $this->amount_due + $currentPaymentAmount;
        $amountPaidExcludingThis = (int) ($this->amount_paid ?? 0) - $currentPaymentAmount;
        $projectedFullPay = max($this->actualTotalForPph23($pph23Cents) - $amountPaidExcludingThis, 0);

        $isFullPayIntent = $amountCents === $amountDueBeforeThisPayment
            || $amountCents === $projectedFullPay
            || $amountCents === $currentPaymentAmount;

        if ($isFullPayIntent) {
            if ($pph23Cents !== null && $pph23Cents !== (int) $this->pph23) {
                $this->update(['pph23' => $pph23Cents]);
                $this->refresh();
            }

            $amountCents = max($this->actualTotalForPph23($pph23Cents) - $amountPaidExcludingThis, 0);
        }

        $this->updatePayment($payment, [
            ...$data,
            'amount' => $amountCents,
            'pph23' => $pph23Cents,
        ]);
    }

    public function recordPayment(array $data): void
    {
        $isRefund = $this->status === InvoiceStatus::Overpaid;

        if ($isRefund) {
            $transactionType = TransactionType::Withdrawal;
            $transactionDescription = "Invoice #{$this->invoice_number}: Refund to {$this->client->name}";
        } else {
            $transactionType = TransactionType::Deposit;
            $transactionDescription = "Invoice #{$this->invoice_number}: Payment from {$this->client->name}";
        }

        $bankAccount = BankAccount::findOrFail($data['bank_account_id']);
        $bankAccountCurrency = $bankAccount->account->currency_code ?? CurrencyAccessor::getDefaultCurrency();

        $invoiceCurrency = $this->currency_code;
        $requiresConversion = $invoiceCurrency !== $bankAccountCurrency;

        // Store the original payment amount in invoice currency before any conversion
        $amountInInvoiceCurrencyCents = $data['amount'];

        if ($requiresConversion) {
            $amountInBankCurrencyCents = CurrencyConverter::convertBalance(
                $amountInInvoiceCurrencyCents,
                $invoiceCurrency,
                $bankAccountCurrency
            );
            $formattedAmountForBankCurrency = $amountInBankCurrencyCents;
        } else {
            $formattedAmountForBankCurrency = $amountInInvoiceCurrencyCents;
        }

        // Create transaction
        $this->transactions()->create([
            'company_id' => $this->company_id,
            'type' => $transactionType,
            'is_payment' => true,
            'posted_at' => $data['posted_at'],
            'amount' => $formattedAmountForBankCurrency,
            'transaction_code' => $data['transaction_code'] ?? null,
            'payment_method' => $data['payment_method'],
            'bank_account_id' => $data['bank_account_id'],
            'account_id' => Account::getAccountsReceivableAccount($this->company_id)->id,
            'description' => $transactionDescription,
            'notes' => $data['notes'] ?? null,
            'meta' => [
                'original_document_currency' => $invoiceCurrency,
                'amount_in_document_currency_cents' => $amountInInvoiceCurrencyCents,
                'pph23_cents' => $data['pph23'] ?? null,
            ],
        ]);
    }

    public function updatePayment(Transaction $payment, array $data): void
    {
        if ($this->status === InvoiceStatus::Cancelled) {
            throw new \InvalidArgumentException('Cannot update payment on a cancelled invoice.');
        }

        if (! $payment->is_payment || (int) $payment->transactionable_id !== (int) $this->id) {
            throw new \InvalidArgumentException('Invalid payment transaction.');
        }

        DB::transaction(function () use ($payment, $data): void {
            $pph23Cents = array_key_exists('pph23', $data) && $data['pph23'] !== null && $data['pph23'] !== ''
                ? (int) $data['pph23']
                : null;

            if ($pph23Cents !== null) {
                $this->update(['pph23' => $pph23Cents]);
                $this->refresh();
            }

            $bankAccount = BankAccount::findOrFail($data['bank_account_id']);
            $bankAccountCurrency = $bankAccount->account->currency_code ?? CurrencyAccessor::getDefaultCurrency();
            $invoiceCurrency = $this->currency_code;
            $requiresConversion = $invoiceCurrency !== $bankAccountCurrency;
            $amountInInvoiceCurrencyCents = (int) $data['amount'];

            $formattedAmountForBankCurrency = $requiresConversion
                ? CurrencyConverter::convertBalance(
                    $amountInInvoiceCurrencyCents,
                    $invoiceCurrency,
                    $bankAccountCurrency
                )
                : $amountInInvoiceCurrencyCents;

            $meta = $payment->meta ?? [];
            $meta['original_document_currency'] = $invoiceCurrency;
            $meta['amount_in_document_currency_cents'] = $amountInInvoiceCurrencyCents;
            $meta['pph23_cents'] = $pph23Cents;

            Transaction::withoutEvents(function () use ($payment, $data, $formattedAmountForBankCurrency, $meta): void {
                $payment->update([
                    'posted_at' => $data['posted_at'],
                    'amount' => $formattedAmountForBankCurrency,
                    'transaction_code' => $data['transaction_code'] ?? null,
                    'payment_method' => $data['payment_method'],
                    'bank_account_id' => $data['bank_account_id'],
                    'notes' => $data['notes'] ?? null,
                    'meta' => $meta,
                ]);
            });

            $payment->refresh();

            app(TransactionObserver::class)->updateInvoiceTotals($this);
            $this->refresh();

            $this->rebuildJournalsForPayment($payment);
            $this->refresh();
        });
    }

    protected function rebuildJournalsForPayment(Transaction $payment): void
    {
        $latestPayment = $this->payments()->orderByDesc('id')->first();
        $paymentsToRebuild = collect([$payment]);

        if (in_array($this->status, [InvoiceStatus::Paid, InvoiceStatus::Overpaid], true) && $payment->id !== $latestPayment?->id) {
            $paymentsToRebuild->push($latestPayment);
        }

        $transactionService = app(TransactionService::class);

        foreach ($paymentsToRebuild->unique('id') as $paymentToRebuild) {
            $paymentToRebuild->refresh();
            $paymentToRebuild->loadMissing(['bankAccount.account', 'account']);

            $paymentToRebuild->journalEntries()->delete();

            if (in_array($this->status, [InvoiceStatus::Paid, InvoiceStatus::Overpaid], true) && $paymentToRebuild->id === $latestPayment?->id) {
                $this->applyPaymentJournalEntriesForTransaction($paymentToRebuild, preferCalculatedCash: true);
            } else {
                $transactionService->createJournalEntries($paymentToRebuild);
            }

            if (! $this->journalTransactionIsBalanced($paymentToRebuild->fresh())) {
                throw new \RuntimeException(
                    "Journal entries do not balance for payment transaction #{$paymentToRebuild->id} on Invoice #{$this->invoice_number}."
                );
            }
        }
    }

    public function approveDraft(?Carbon $approvedAt = null): void
    {
        if (! $this->isDraft() && $this->status !== InvoiceStatus::Sent) {
            throw new \RuntimeException('Invoice is not in draft or sent status.');
        }

        $this->updateApprovalTransaction();

        $approvedAt ??= company_now();

        if ($this->status === InvoiceStatus::Sent) {
            // Backfill approval: set approved_at only, keep status Sent
            $this->update(['approved_at' => $approvedAt]);
        } else {
            $this->update([
                'approved_at' => $approvedAt,
                'status' => InvoiceStatus::Unsent,
            ]);
        }
    }

    public function finalizeInvoice(?Carbon $finalizedAt = null): void
    {
        if (! $this->canBeFinalized()) {
            throw new \RuntimeException('Invoice cannot be finalized.');
        }

        if (! $this->tax_code_id || ! $this->tax_number || ! $this->tax_date) {
            throw new \RuntimeException('Tax data (tax_code_id, tax_number, tax_date) must be filled before finalizing.');
        }

        $finalizedAt ??= company_now();

        $this->update([
            'finalized_at' => $finalizedAt,
        ]);

        SendInvoiceApprovedNotification::dispatch($this->id);
    }

    public function createApprovalTransaction(): void
    {
        $this->loadMissing(['lineItems.adjustments', 'lineItems.offering', 'client']);

        $baseDescription = "{$this->client->name}: Invoice #{$this->invoice_number}";

        $journalEntryData = [];
        $totalInInvoiceCurrency = $this->total;
        $journalEntryData[] = [
            'type' => JournalEntryType::Debit,
            'account_id' => Account::getAccountsReceivableAccount($this->company_id)->id,
            'amount_in_invoice_currency' => $totalInInvoiceCurrency,
            'description' => $baseDescription,
        ];

        $totalLineItemSubtotalInInvoiceCurrency = (int) $this->lineItems()->sum('subtotal');
        $invoiceDiscountTotalInInvoiceCurrency = (int) $this->discount_total;
        $remainingDiscountInInvoiceCurrency = $invoiceDiscountTotalInInvoiceCurrency;
        $taxCreditedFromLineItems = 0;

        foreach ($this->lineItems as $index => $lineItem) {
            $lineItemDescription = "{$baseDescription} › {$lineItem->offering->name}";
            $lineItemSubtotalInInvoiceCurrency = $lineItem->subtotal;

            // pendapatan
            $journalEntryData[] = [
                'type' => JournalEntryType::Credit,
                'account_id' => $lineItem->offering->income_account_id,
                'amount_in_invoice_currency' => $lineItemSubtotalInInvoiceCurrency,
                'description' => $lineItemDescription,
            ];

            // adjusment (discount & tax)
            foreach ($lineItem->adjustments as $adjustment) {
                $adjustmentAmountInInvoiceCurrency = $lineItem->calculateAdjustmentTotalAmount($adjustment);
                if ($adjustment->category === AdjustmentCategory::Tax) {
                    $taxCreditedFromLineItems += $adjustmentAmountInInvoiceCurrency;
                }
                $journalEntryData[] = [
                    'type' => $adjustment->category->isDiscount() ? JournalEntryType::Debit : JournalEntryType::Credit,
                    'account_id' => $adjustment->account_id,
                    'amount_in_invoice_currency' => $adjustmentAmountInInvoiceCurrency,
                    'description' => $lineItemDescription,
                ];
            }

            // discount per-document
            if ($this->discount_method->isPerDocument() && $totalLineItemSubtotalInInvoiceCurrency > 0) {
                $lineItemSubtotalInInvoiceCurrency = $lineItem->subtotal;

                if ($index === $this->lineItems->count() - 1) {
                    $lineItemDiscountInInvoiceCurrency = $remainingDiscountInInvoiceCurrency;
                } else {
                    $lineItemDiscountInInvoiceCurrency = (int) round(
                        ($lineItemSubtotalInInvoiceCurrency / $totalLineItemSubtotalInInvoiceCurrency) * $invoiceDiscountTotalInInvoiceCurrency
                    );
                    $remainingDiscountInInvoiceCurrency -= $lineItemDiscountInInvoiceCurrency;
                }

                if ($lineItemDiscountInInvoiceCurrency > 0) {
                    $journalEntryData[] = [
                        'type' => JournalEntryType::Debit,
                        'account_id' => Account::getSalesDiscountAccount($this->company_id)->id,
                        'amount_in_invoice_currency' => $lineItemDiscountInInvoiceCurrency,
                        'description' => "{$lineItemDescription} (Proportional Discount)",
                    ];
                }
            }
        }

        // profit
        if ($this->profit_total > 0) {
            $profitTotal = $this->profit_total;
            $profitDescription = 'Profit ' . $baseDescription;
            $journalEntryData[] = [
                'type' => JournalEntryType::Credit,
                'account_id' => Account::getAccountsProfit($this->company_id)->id,
                'amount_in_invoice_currency' => $profitTotal,
                'description' => $profitDescription,
            ];

            $additionalTax = (int) $this->tax_total - $taxCreditedFromLineItems;
            if ($additionalTax > 0) {
                $journalEntryData[] = [
                    'type' => JournalEntryType::Credit,
                    'account_id' => $this->salesTaxAccountId(),
                    'amount_in_invoice_currency' => $additionalTax,
                    'description' => $profitDescription,
                ];
            }
        }

        // Convert amounts to default currency
        $totalDebitsInDefaultCurrency = 0;
        $totalCreditsInDefaultCurrency = 0;

        foreach ($journalEntryData as &$entry) {
            $entry['amount_in_default_currency'] = $this->formatAmountToDefaultCurrency($entry['amount_in_invoice_currency']);

            if ($entry['type'] === JournalEntryType::Debit) {
                $totalDebitsInDefaultCurrency += $entry['amount_in_default_currency'];
            } else {
                $totalCreditsInDefaultCurrency += $entry['amount_in_default_currency'];
            }
        }

        unset($entry);

        // Handle currency conversion imbalance
        $imbalance = $totalDebitsInDefaultCurrency - $totalCreditsInDefaultCurrency;
        if ($imbalance !== 0) {
            $targetType = $imbalance > 0 ? JournalEntryType::Credit : JournalEntryType::Debit;
            $adjustmentAmount = abs($imbalance);

            // Find last entry of target type and adjust it
            $lastKey = array_key_last(array_filter($journalEntryData, fn ($entry) => $entry['type'] === $targetType, ARRAY_FILTER_USE_BOTH));
            $journalEntryData[$lastKey]['amount_in_default_currency'] += $adjustmentAmount;

            if ($targetType === JournalEntryType::Debit) {
                $totalDebitsInDefaultCurrency += $adjustmentAmount;
            } else {
                $totalCreditsInDefaultCurrency += $adjustmentAmount;
            }
        }

        if ($totalDebitsInDefaultCurrency !== $totalCreditsInDefaultCurrency) {
            throw new \Exception('Journal entries do not balance for Invoice #' . $this->invoice_number . '. Debits: ' . $totalDebitsInDefaultCurrency . ', Credits: ' . $totalCreditsInDefaultCurrency);
        }

        // Create the transaction using the sum of debits
        $transaction = $this->transactions()->create([
            'company_id' => $this->company_id,
            'type' => TransactionType::Journal,
            'posted_at' => $this->date,
            'amount' => $totalDebitsInDefaultCurrency,
            'description' => 'Invoice Approval for Invoice #' . $this->invoice_number,
        ]);

        Log::debug('debug journal entry data ' . __FILE__, [
            'journalEntry' => $journalEntryData,
        ]);

        // Create all journal entries
        foreach ($journalEntryData as $entry) {
            $transaction->journalEntries()->create([
                'company_id' => $this->company_id,
                'type' => $entry['type'],
                'account_id' => $entry['account_id'],
                'amount' => $entry['amount_in_default_currency'],
                'description' => $entry['description'],
            ]);
        }
    }

    public function updateApprovalTransaction(): void
    {
        $this->approvalTransaction?->delete();

        $this->createApprovalTransaction();
    }

    public function convertAmountToDefaultCurrency(int $amountCents): int
    {
        $defaultCurrency = CurrencyAccessor::getDefaultCurrency();
        $needsConversion = $this->currency_code !== $defaultCurrency;

        if ($needsConversion) {
            return CurrencyConverter::convertBalance($amountCents, $this->currency_code, $defaultCurrency);
        }

        return $amountCents;
    }

    public function formatAmountToDefaultCurrency(int $amountCents): int
    {
        return $this->convertAmountToDefaultCurrency($amountCents);
    }

    // TODO: Potentially handle this another way
    public static function getBlockedApproveAction(string $action = Action::class): MountableAction
    {
        return $action::make('blockedApprove')
            ->label('Approve')
            ->icon('heroicon-m-check-circle')
            ->visible(fn (self $record) => $record->canBeApproved() && $record->hasInactiveAdjustments())
            ->requiresConfirmation()
            ->modalAlignment(Alignment::Start)
            ->modalIconColor('danger')
            ->modalDescription(function (self $record) {
                $inactiveAdjustments = collect();

                foreach ($record->lineItems as $lineItem) {
                    foreach ($lineItem->adjustments as $adjustment) {
                        if ($adjustment->isInactive() && $inactiveAdjustments->doesntContain($adjustment->name)) {
                            $inactiveAdjustments->push($adjustment->name);
                        }
                    }
                }

                $output = "<p class='text-sm mb-4'>This invoice contains inactive adjustments that need to be addressed before approval:</p>";
                $output .= "<ul role='list' class='list-disc list-inside space-y-1 text-sm'>";

                foreach ($inactiveAdjustments as $name) {
                    $output .= "<li class='py-1'><span class='font-medium'>{$name}</span></li>";
                }

                $output .= '</ul>';
                $output .= "<p class='text-sm mt-4'>Please update these adjustments before approving the invoice.</p>";

                return new HtmlString($output);
            })
            ->modalSubmitAction(function (StaticAction $action, self $record) {
                $action->label('Edit Invoice')
                    ->url(InvoiceResource\Pages\EditInvoice::getUrl(['record' => $record->id]));
            });
    }

    public static function getApproveDraftAction(string $action = Action::class): MountableAction
    {
        return $action::make('approveDraft')
            ->label('Approve')
            ->icon('heroicon-m-check-circle')
            ->visible(function (self $record) {
                return $record->canBeApproved();
            })
            ->requiresConfirmation()
            ->databaseTransaction()
            ->successNotificationTitle('Invoice approved')
            ->action(function (self $record, MountableAction $action, Component $livewire) {
                if ($record->hasInactiveAdjustments()) {
                    $isViewPage = $livewire instanceof InvoiceResource\Pages\ViewInvoice;

                    if (! $isViewPage) {
                        redirect(InvoiceResource\Pages\ViewInvoice::getUrl(['record' => $record->id]));
                    } else {
                        Notification::make()
                            ->warning()
                            ->title('Cannot approve invoice')
                            ->body('This invoice has inactive adjustments that must be addressed first.')
                            ->persistent()
                            ->send();
                    }
                } else {
                    try {
                        $record->approveDraft();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Something went wrong')
                            ->body($e->getMessage())
                            ->send();
                        $action->halt();
                    }

                    $action->success();
                }
            });
    }

    public static function getUnifiedApproveAction(string $action = Action::class): MountableAction
    {
        return $action::make('unifiedApprove')
            ->label(function (self $record) {
                return 'Approve';
            })
            ->icon('heroicon-m-check-circle')
            ->visible(function (self $record) {
                // Visible if can be approved (level 1) or finalized (level 2)
                return $record->canBeApproved() || $record->canBeFinalized();
            })
            ->requiresConfirmation()
            ->databaseTransaction()
            ->successNotificationTitle(function (self $record) {
                if ($record->canBeApproved()) {
                    return 'Invoice approved successfully! You can now print the document.';
                } elseif ($record->canBeFinalized()) {
                    return 'Invoice finalized successfully! The document is now ready.';
                }

                return 'Invoice approved successfully';
            })
            ->action(function (self $record, MountableAction $action, Component $livewire) {
                // Approval Level 1: Draft → Unsent
                if ($record->canBeApproved()) {
                    if ($record->hasInactiveAdjustments()) {
                        $isViewPage = $livewire instanceof InvoiceResource\Pages\ViewInvoice;

                        if (! $isViewPage) {
                            redirect(InvoiceResource\Pages\ViewInvoice::getUrl(['record' => $record->id]));
                        } else {
                            Notification::make()
                                ->warning()
                                ->title('Cannot approve invoice')
                                ->body('This invoice has inactive adjustments that must be addressed first.')
                                ->persistent()
                                ->send();
                        }
                    } else {
                        try {
                            $record->approveDraft();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('Something went wrong')
                                ->body($e->getMessage())
                                ->send();
                            $action->halt();

                            return;
                        }

                        $action->success();
                    }
                }
                // Approval Level 2: Finalize
                elseif ($record->canBeFinalized()) {
                    if (! $record->tax_code_id || ! $record->tax_number || ! $record->tax_date) {
                        Notification::make()
                            ->title('Tax data is missing. Please fill it out first.')
                            ->danger()
                            ->send();
                        $action->halt();

                        return;
                    }

                    try {
                        $record->finalizeInvoice();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Something went wrong')
                            ->body($e->getMessage())
                            ->send();
                        $action->halt();

                        return;
                    }

                    $action->success();
                }
            });
    }

    public static function getFinalizeInvoiceAction(string $action = Action::class): MountableAction
    {
        return $action::make('finalizeInvoice')
            ->label('Finalize Invoice')
            ->icon('heroicon-m-check-circle')
            ->visible(function (self $record) {
                return $record->canBeFinalized();
            })
            ->requiresConfirmation()
            ->databaseTransaction()
            ->successNotificationTitle('Invoice finalized')
            ->action(function (self $record, MountableAction $action, Component $livewire) {
                if (! $record->tax_code_id || ! $record->tax_number || ! $record->tax_date) {
                    Notification::make()
                        ->title('Tax data is missing. Please fill it out first.')
                        ->danger()
                        ->send();
                    $action->halt();

                    return;
                }

                try {
                    $record->finalizeInvoice();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('Something went wrong')
                        ->body($e->getMessage())
                        ->send();
                    $action->halt();

                    return;
                }

                $action->success();
            });
    }

    public static function getMarkAsSentAction(string $action = Action::class): MountableAction
    {
        return $action::make('markAsSent')
            ->label('Mark as sent')
            ->icon('heroicon-m-paper-airplane')
            ->visible(static function (self $record) {
                return $record->canBeMarkedAsSent();
            })
            ->successNotificationTitle('Invoice sent')
            ->action(function (self $record, MountableAction $action) {
                $record->markAsSent();

                $action->success();
            });
    }

    public function markAsSent(?Carbon $sentAt = null): void
    {
        $sentAt ??= company_now();

        $this->update([
            'status' => InvoiceStatus::Sent,
            'last_sent_at' => $sentAt,
        ]);
    }

    public function markAsViewed(?Carbon $viewedAt = null): void
    {
        $viewedAt ??= company_now();

        $this->update([
            'status' => InvoiceStatus::Viewed,
            'last_viewed_at' => $viewedAt,
        ]);
    }

    public static function getPrintDocumentAction(string $action = Action::class): MountableAction
    {
        return parent::getPrintDocumentAction($action)
            ->visible(function (self $record) {
                // Cannot print if status is Draft
                return $record->status !== InvoiceStatus::Draft;
            });
    }

    public static function getReplicateAction(string $action = ReplicateAction::class): MountableAction
    {
        return $action::make()
            ->excludeAttributes([
                'status',
                'amount_paid',
                'amount_due',
                'created_by',
                'updated_by',
                'created_at',
                'updated_at',
                'invoice_number',
                'date',
                'due_date',
                'approved_at',
                'paid_at',
                'last_sent_at',
                'last_viewed_at',
            ])
            ->modal(false)
            ->beforeReplicaSaved(function (self $original, self $replica) {
                $replica->status = InvoiceStatus::Draft;
                $replica->invoice_number = self::getNextDocumentNumber();
                $replica->date = company_today();
                $replica->due_date = company_today()->addDays($original->company->defaultInvoice->payment_terms->getDays());
            })
            ->databaseTransaction()
            ->after(function (self $original, self $replica) {
                $original->replicateLineItems($replica);
            })
            ->successRedirectUrl(static function (self $replica) {
                return InvoiceResource::getUrl('edit', ['record' => $replica]);
            });
    }

    /**
     * Soft-delete all transactions linked to this invoice (including payments and
     * approval journals). Journal lines are soft-deleted via TransactionObserver.
     * Resets payment fields and sets status to cancelled.
     */
    public function cancelWithAccountingVoid(): void
    {
        foreach ($this->transactions()->get() as $transaction) {
            $transaction->journalEntries()->each(function (JournalEntry $entry): void {
                $entry->delete();
            });
        }

        static::withoutEvents(function (): void {
            foreach ($this->transactions()->get() as $transaction) {
                $transaction->delete();
            }
        });

        DB::table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->update([
                'status' => InvoiceStatus::Cancelled->value,
                'amount_paid' => 0,
                'paid_at' => null,
                'updated_at' => now(),
            ]);

        $this->refresh();
    }

    public static function getCancelInvoiceAction(string $action = Action::class): MountableAction
    {
        return $action::make('cancelInvoice')
            ->label('Cancel Invoice')
            ->icon('heroicon-m-x-circle')
            ->color('danger')
            ->visible(function (self $record) {
                return $record->status !== InvoiceStatus::Cancelled;
            })
            ->requiresConfirmation()
            ->modalHeading(function (self $record) {
                return $record->hasPayments()
                    ? 'Peringatan: Invoice sudah ada pembayaran'
                    : 'Cancel Invoice';
            })
            ->modalDescription(function (self $record) {
                if ($record->hasPayments()) {
                    return new HtmlString(
                        "<p class='text-sm mb-2'>Invoice ini sudah memiliki pembayaran. Membatalkan invoice akan meng-soft-delete transaksi dan baris jurnal terkait serta mengatur ulang jumlah terbayar. Hal ini dapat mempengaruhi rekonsiliasi dan laporan.</p>" .
                        "<p class='text-sm'>Apakah Anda yakin ingin membatalkan invoice ini?</p>"
                    );
                }

                return 'Invoice akan dibatalkan (status Cancelled), transaksi dan jurnal terkait akan di-soft-delete, serta jumlah terbayar direset. Apakah Anda yakin ingin melanjutkan?';
            })
            ->modalSubmitActionLabel('Batalkan Invoice')
            ->databaseTransaction()
            ->successNotificationTitle('Invoice cancelled successfully')
            ->failureNotificationTitle('Failed to cancel invoice')
            ->action(function (self $record, MountableAction $action) {
                if ($record->status === InvoiceStatus::Cancelled) {
                    Notification::make()
                        ->title('Cannot cancel invoice')
                        ->body('This invoice is already cancelled.')
                        ->danger()
                        ->send();
                    $action->cancel();

                    return;
                }

                $record->cancelWithAccountingVoid();
                $record->refresh();

                $action->success();
            })
            ->successRedirectUrl(function (self $record) {
                return InvoiceResource::getUrl('view', ['record' => $record]);
            });
    }

    public function replicateLineItems(Model $target): void
    {
        $this->lineItems->each(function (DocumentLineItem $lineItem) use ($target) {
            $replica = $lineItem->replicate([
                'documentable_id',
                'documentable_type',
                'subtotal',
                'total',
                'created_by',
                'updated_by',
                'created_at',
                'updated_at',
            ]);

            $replica->documentable_id = $target->id;
            $replica->documentable_type = $target->getMorphClass();
            $replica->save();

            $replica->adjustments()->sync($lineItem->adjustments->pluck('id'));
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
