<?php

use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountDefaultKey;
use App\Enums\Accounting\JournalEntryType;
use App\Enums\Accounting\PaymentMethod;
use App\Models\AccountDefault;
use App\Models\Accounting\Account;
use App\Models\Accounting\Adjustment;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\AccountSubtype;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
use App\Models\Banking\BankAccount;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use App\Scopes\CurrentCompanyScope;

beforeEach(function () {
    $this->defaultCurrency = CurrencyAccessor::getDefaultCurrency();
    $this->withOfferings();
    ensureInvoiceAccountDefaultsForCompany($this->testCompany->id);
});

function ensureInvoiceAccountDefaultsForCompany(int $companyId): void
{
    $defaultCurrency = CurrencyAccessor::getDefaultCurrency();

    $assetSubtype = AccountSubtype::query()
        ->where('company_id', $companyId)
        ->where('category', AccountCategory::Asset)
        ->first();

    $subtypeId = $assetSubtype?->id ?? AccountSubtype::query()
        ->where('company_id', $companyId)
        ->value('id');

    $defaultsToEnsure = [
        AccountDefaultKey::PPh23->value => 'PPh 23 (Test)',
        AccountDefaultKey::PPn->value => 'PPN (Test)',
        AccountDefaultKey::Profit->value => 'Profit (Test)',
        AccountDefaultKey::AccountsReceivable->value => 'Accounts Receivable (Test)',
        AccountDefaultKey::SalesDiscount->value => 'Sales Discount (Test)',
    ];

    foreach ($defaultsToEnsure as $key => $accountName) {
        $exists = AccountDefault::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->exists();

        if ($exists) {
            continue;
        }

        $account = Account::factory()->create([
            'company_id' => $companyId,
            'subtype_id' => $subtypeId,
            'name' => $accountName,
            'currency_code' => $defaultCurrency,
            'archived' => false,
            'default' => false,
        ]);

        AccountDefault::query()->create([
            'company_id' => $companyId,
            'account_id' => $account->id,
            'key' => $key,
        ]);
    }
}

it('creates a basic invoice with line items and calculates totals correctly', function () {
    $invoice = Invoice::factory()
        ->withLineItems()
        ->state([
            'company_id' => $this->testCompany->id,
        ])
        ->create();

    $invoice->refresh();

    expect($invoice)
        ->hasLineItems()->toBeTrue()
        ->lineItems->count()->toBe(3)
        ->subtotal->toBeGreaterThan(0)
        ->total->toBeGreaterThan(0)
        ->amount_due->toBe($invoice->total);
});

test('approved invoices are marked as Unsent when not Overdue', function () {
    $invoice = Invoice::factory()
        ->withLineItems()
        ->state([
            'company_id' => $this->testCompany->id,
            'due_date' => now()->addDays(30),
        ])
        ->create();

    $invoice->refresh();

    $invoice->approveDraft();
    $invoice->refresh();

    expect($invoice)
        ->hasLineItems()->toBeTrue()
        ->status->toBe(InvoiceStatus::Unsent)
        ->wasApproved()->toBeTrue()
        ->approvalTransaction->not->toBeNull();
});

test('approved invoices are marked as Overdue when Overdue', function () {
    $invoice = Invoice::factory()
        ->withLineItems()
        ->state([
            'company_id' => $this->testCompany->id,
            'due_date' => now()->subDays(30),
        ])
        ->create();

    $invoice->refresh();

    $invoice->approveDraft();
    $invoice->refresh();

    expect($invoice)
        ->hasLineItems()->toBeTrue()
        ->status->toBe(InvoiceStatus::Overdue)
        ->wasApproved()->toBeTrue()
        ->approvalTransaction->not->toBeNull();
});

test('partial payment ke-2 di-journal incremental (cash & receivable tidak jadi full)', function () {
    $activeCompanyId = auth()->user()?->current_company_id ?? $this->testCompany->id;

    $invoice = Invoice::factory()
        ->withLineItems()
        ->state([
            'company_id' => $activeCompanyId,
        ])
        ->create();

    $invoice->refresh();
    $invoice->approveDraft();
    $invoice->refresh();

    // Beberapa environment test mungkin belum punya mapping COA default untuk PPh23.
    // Hook saat status Paid butuh `Account::getAccountsPPh23()` sehingga pastikan dulu.
    $companyId = $invoice->company_id;
    $accountDefaultQuery = AccountDefault::query()->withoutGlobalScope(CurrentCompanyScope::class);
    $hasPph23Mapping = $accountDefaultQuery
        ->where('company_id', $companyId)
        ->where('key', AccountDefaultKey::PPh23->value)
        ->exists();

    if (! $hasPph23Mapping) {
        $defaultCurrency = CurrencyAccessor::getDefaultCurrency();

        $assetSubtype = AccountSubtype::query()
            ->where('company_id', $companyId)
            ->where('category', AccountCategory::Asset)
            ->first();

        $subtypeId = $assetSubtype?->id ?? AccountSubtype::query()
                ->where('company_id', $companyId)
                ->value('id');

        $pph23Account = Account::factory()->create([
            'company_id' => $companyId,
            'subtype_id' => $subtypeId,
            'name' => 'PPh 23 (Test)',
            'currency_code' => $invoice->currency_code ?? $defaultCurrency,
            'archived' => false,
            'default' => false,
        ]);

        $accountDefaultQuery->create([
            'company_id' => $companyId,
            'account_id' => $pph23Account->id,
            'key' => AccountDefaultKey::PPh23->value,
        ]);
    }

    // Pastikan mapping benar-benar ada sebelum lanjut payment (untuk menghindari error hook di status Paid).
    expect(
        $accountDefaultQuery
            ->where('company_id', $companyId)
            ->where('key', AccountDefaultKey::PPh23->value)
            ->exists()
    )->toBeTrue();

    $payment1Amount = (int) floor($invoice->amount_due / 2);
    expect($payment1Amount)->toBeGreaterThan(0);

    $bankAccount = BankAccount::query()
        ->where('company_id', $invoice->company_id)
        ->where('enabled', true)
        ->first();
    expect($bankAccount)->not->toBeNull();

    $invoiceCurrency = $invoice->currency_code;

    $invoice->recordPayment([
        'posted_at'       => now()->toDateString(),
        'payment_method'  => PaymentMethod::BankPayment,
        'bank_account_id' => $bankAccount->id,
        'amount'          => $payment1Amount,
        'notes'           => 'Test partial payment #1',
    ]);

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Partial);

    $payment2Amount = (int) $invoice->amount_due;
    expect($payment2Amount)->toBeGreaterThan(0);

    $invoice->recordPayment([
        'posted_at'       => now()->toDateString(),
        'payment_method'  => PaymentMethod::BankPayment,
        'bank_account_id' => $bankAccount->id,
        'amount'          => $payment2Amount,
        'notes'           => 'Test partial payment #2',
    ]);

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Paid);

    $payments = Transaction::query()
        ->where('transactionable_type', Invoice::class)
        ->where('transactionable_id', $invoice->id)
        ->where('is_payment', true)
        ->orderBy('id', 'asc')
        ->get();

    expect($payments->count())->toBeGreaterThanOrEqual(2);
    $payment1Trx = $payments->first();
    $payment2Trx = $payments->last();

    $receivableAccount = Account::getAccountsReceivableAccount($invoice->company_id);

    $approvalTrx = $invoice->approvalTransaction;
    expect($approvalTrx)->not->toBeNull();

    $totalReceivable = (int) JournalEntry::query()
        ->where('transaction_id', $approvalTrx->id)
        ->where('account_id', $receivableAccount->id)
        ->sum('amount');

    $payment1ReceivableCredits = (int) JournalEntry::query()
        ->where('transaction_id', $payment1Trx->id)
        ->where('account_id', $receivableAccount->id)
        ->where('type', JournalEntryType::Credit)
        ->sum('amount');

    $payment1ReceivableDebits = (int) JournalEntry::query()
        ->where('transaction_id', $payment1Trx->id)
        ->where('account_id', $receivableAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');

    $payment1NetReceivable = $payment1ReceivableCredits - $payment1ReceivableDebits;
    $expectedRemainingReceivable = $totalReceivable - $payment1NetReceivable;

    $payment2ReceivableCredit = (int) JournalEntry::query()
        ->where('transaction_id', $payment2Trx->id)
        ->where('account_id', $receivableAccount->id)
        ->where('type', JournalEntryType::Credit)
        ->sum('amount');

    expect($payment2ReceivableCredit)->toBe($expectedRemainingReceivable);

    $cashAccountId = $payment2Trx->bankAccount->account_id;
    $payment2CashDebitDefault = (int) JournalEntry::query()
        ->where('transaction_id', $payment2Trx->id)
        ->where('account_id', $cashAccountId)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');

    $defaultCurrency = CurrencyAccessor::getDefaultCurrency();
    $expectedCashDebitDefault = CurrencyConverter::convertBalance(
        $payment2Amount,
        $invoiceCurrency ?? $defaultCurrency,
        $defaultCurrency
    );

    expect($payment2CashDebitDefault)->toBe($expectedCashDebitDefault);
});

test('record payment respects manual amount and pph23', function () {
    $companyId = auth()->user()?->current_company_id ?? $this->testCompany->id;

    $invoice = Invoice::factory()
        ->withLineItems()
        ->state([
            'company_id' => $companyId,
        ])
        ->create();

    $invoice->refresh();
    $invoice->approveDraft();
    $invoice->refresh();

    $approvalTrx = $invoice->approvalTransaction;
    expect($approvalTrx)->not->toBeNull();

    $receivableAccount = Account::getAccountsReceivableAccount($invoice->company_id);
    $ppnAccount = Account::getAccountsPPn($invoice->company_id);
    $pph23Account = Account::getAccountsPPh23($invoice->company_id);

    $remainingReceivable = (int) JournalEntry::query()
        ->where('transaction_id', $approvalTrx->id)
        ->where('account_id', $receivableAccount->id)
        ->sum('amount');

    $ppnAmount = (int) JournalEntry::query()
        ->where('transaction_id', $approvalTrx->id)
        ->where('account_id', $ppnAccount->id)
        ->sum('amount');

    $wapuAmount = 0;
    $wapuAccount = Account::getAccountsWapuIfConfigured($invoice->company_id);
    if ($wapuAccount) {
        $wapuAmount = (int) JournalEntry::query()
            ->where('transaction_id', $approvalTrx->id)
            ->where('account_id', $wapuAccount->id)
            ->sum('amount');
    }

    $defaultPph23 = (int) round(($invoice->subtotal + $invoice->profit_total - ($invoice->discount_total ?? 0)) * 0.02);
    $manualPph23 = max($defaultPph23 - 50_000, 1_000);
    $manualCash = $remainingReceivable - $ppnAmount - $wapuAmount - $manualPph23;

    expect($manualCash)->toBeGreaterThan(0);

    $bankAccount = BankAccount::query()
        ->where('company_id', $invoice->company_id)
        ->where('enabled', true)
        ->first();
    expect($bankAccount)->not->toBeNull();

    $invoice->update(['pph23' => $manualPph23]);

    $invoice->recordPayment([
        'posted_at' => now()->toDateString(),
        'payment_method' => PaymentMethod::BankPayment,
        'bank_account_id' => $bankAccount->id,
        'amount' => $manualCash,
        'pph23' => $manualPph23,
        'notes' => 'Manual amount and PPh23 test',
    ]);

    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and((int) $invoice->pph23)->toBe($manualPph23);

    $paymentTrx = Transaction::query()
        ->where('transactionable_type', Invoice::class)
        ->where('transactionable_id', $invoice->id)
        ->where('is_payment', true)
        ->orderByDesc('id')
        ->first();

    expect($paymentTrx)->not->toBeNull()
        ->and((int) ($paymentTrx->meta['pph23_cents'] ?? 0))->toBe($manualPph23)
        ->and((int) ($paymentTrx->meta['amount_in_document_currency_cents'] ?? 0))->toBe($manualCash);

    $pph23JournalAmount = (int) JournalEntry::query()
        ->where('transaction_id', $paymentTrx->id)
        ->where('account_id', $pph23Account->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');

    $cashAccountId = $paymentTrx->bankAccount->account_id;
    $cashDebit = (int) JournalEntry::query()
        ->where('transaction_id', $paymentTrx->id)
        ->where('account_id', $cashAccountId)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');

    expect($pph23JournalAmount)->toBe($manualPph23)
        ->and($cashDebit)->toBe($manualCash);
});

function removePpnWapuConfigurationForCompany(int $companyId): void
{
    Adjustment::query()
        ->where('company_id', $companyId)
        ->where('name', 'PPN WAPU')
        ->delete();

    AccountDefault::query()
        ->withoutGlobalScope(CurrentCompanyScope::class)
        ->where('company_id', $companyId)
        ->where('key', AccountDefaultKey::PPnWapu->value)
        ->delete();
}

function ensurePpnWapuConfigurationForCompany(int $companyId): Account
{
    $existing = Account::getAccountsWapuIfConfigured($companyId);
    if ($existing) {
        return $existing;
    }

    $defaultCurrency = CurrencyAccessor::getDefaultCurrency();
    $assetSubtype = AccountSubtype::query()
        ->where('company_id', $companyId)
        ->where('category', AccountCategory::Asset)
        ->first();

    $wapuAccount = Account::factory()->create([
        'company_id' => $companyId,
        'subtype_id' => $assetSubtype?->id,
        'name' => 'PPN WAPU (Test)',
        'currency_code' => $defaultCurrency,
    ]);

    AccountDefault::query()->create([
        'company_id' => $companyId,
        'account_id' => $wapuAccount->id,
        'key' => AccountDefaultKey::PPnWapu->value,
    ]);

    Adjustment::factory()->salesTax('PPN WAPU')->create([
        'company_id' => $companyId,
        'name' => 'PPN WAPU',
        'account_id' => $wapuAccount->id,
        'rate' => 110_000,
        'computation' => AdjustmentComputation::Percentage,
    ]);

    return $wapuAccount;
}

function attachElevenPercentPpnToInvoiceLineItem(Invoice $invoice): void
{
    $ppnAccount = Account::getAccountsPPn($invoice->company_id);
    $adjustment = Adjustment::factory()->salesTax('PPN 11%')->create([
        'company_id' => $invoice->company_id,
        'account_id' => $ppnAccount->id,
        'rate' => 110_000,
        'computation' => AdjustmentComputation::Percentage,
    ]);

    $lineItem = $invoice->lineItems->first();
    $lineItem->adjustments()->sync([$adjustment->id]);
    $lineItem->updateQuietly([
        'tax_total' => $lineItem->calculateTaxTotalAmount(),
    ]);
}

function sumApprovalSalesTaxCredits(Invoice $invoice): int
{
    $approvalTransaction = $invoice->approvalTransaction;

    return (int) JournalEntry::query()
        ->where('transaction_id', $approvalTransaction->id)
        ->where('account_id', $invoice->salesTaxAccountId())
        ->where('type', JournalEntryType::Credit)
        ->sum('amount');
}

test('approveDraft succeeds without PPN WAPU adjustment or account default', function () {
    $companyId = $this->testCompany->id;
    removePpnWapuConfigurationForCompany($companyId);

    expect(Adjustment::companyHasPpnWapu($companyId))->toBeFalse();

    $invoice = Invoice::factory()
        ->withLineItems()
        ->state([
            'company_id' => $companyId,
            'due_date' => now()->addDays(30),
        ])
        ->create();

    $invoice->refresh();
    $invoice->approveDraft();
    $invoice->refresh();

    expect($invoice->wasApproved())->toBeTrue()
        ->and($invoice->approvalTransaction)->not->toBeNull();
});

test('approveDraft with profit journals tax to PPN when WAPU is not configured', function () {
    $companyId = $this->testCompany->id;
    removePpnWapuConfigurationForCompany($companyId);

    $defaultCurrency = CurrencyAccessor::getDefaultCurrency();
    $subtotal = 1_000_000;
    $profitTotal = 100_000;
    $taxTotal = 121_000;
    $total = $subtotal + $profitTotal + $taxTotal;

    $invoice = Invoice::factory()
        ->withLineItems(1)
        ->state([
            'company_id' => $companyId,
            'currency_code' => $defaultCurrency,
            'due_date' => now()->addDays(30),
            'profit_total' => $profitTotal,
            'tax_total' => $taxTotal,
            'discount_method' => \App\Enums\Accounting\DocumentDiscountMethod::PerLineItem,
            'discount_total' => 0,
        ])
        ->create();

    $invoice->refresh();
    $invoice->lineItems->first()->updateQuietly([
        'subtotal' => $subtotal,
        'unit_price' => $subtotal,
        'quantity' => 1,
    ]);
    attachElevenPercentPpnToInvoiceLineItem($invoice);
    $invoice->updateQuietly([
        'subtotal' => $subtotal,
        'profit_total' => $profitTotal,
        'tax_total' => $taxTotal,
        'total' => $total,
    ]);

    $invoice->refresh();
    $invoice->load(['lineItems.adjustments', 'client']);
    $invoice->approveDraft();
    $invoice->refresh();

    $wapuAccount = Account::getAccountsWapuIfConfigured($companyId);
    $receivableAccount = Account::getAccountsReceivableAccount($companyId);

    $approvalTransaction = $invoice->approvalTransaction;
    expect($approvalTransaction)->not->toBeNull();

    $arDebit = (int) JournalEntry::query()
        ->where('transaction_id', $approvalTransaction->id)
        ->where('account_id', $receivableAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');

    $expectedAr = $invoice->formatAmountToDefaultCurrency($total);
    $expectedTax = $invoice->formatAmountToDefaultCurrency($taxTotal);

    expect($wapuAccount)->toBeNull()
        ->and($arDebit)->toBe($expectedAr)
        ->and(sumApprovalSalesTaxCredits($invoice))->toBe($expectedTax)
        ->and($invoice->needsProfitJournalFix())->toBeFalse();
});

test('approveDraft with profit journals AR and WAPU tax match invoice totals', function () {
    $companyId = $this->testCompany->id;
    ensurePpnWapuConfigurationForCompany($companyId);

    $defaultCurrency = CurrencyAccessor::getDefaultCurrency();
    $subtotal = 1_000_000_000;
    $profitTotal = 100_000_000;
    $taxTotal = 121_000_000;
    $total = $subtotal + $profitTotal + $taxTotal;

    $invoice = Invoice::factory()
        ->withLineItems(1)
        ->state([
            'company_id' => $companyId,
            'currency_code' => $defaultCurrency,
            'due_date' => now()->addDays(30),
            'profit_total' => $profitTotal,
            'tax_total' => $taxTotal,
            'discount_method' => \App\Enums\Accounting\DocumentDiscountMethod::PerLineItem,
            'discount_total' => 0,
        ])
        ->create();

    $invoice->refresh();
    $invoice->lineItems->first()->updateQuietly([
        'subtotal' => $subtotal,
        'unit_price' => $subtotal,
        'quantity' => 1,
    ]);

    $wapuAdjustment = Adjustment::query()
        ->where('company_id', $companyId)
        ->where('name', 'PPN WAPU')
        ->first();
    $invoice->lineItems->first()->adjustments()->sync([$wapuAdjustment->id]);
    $invoice->lineItems->first()->updateQuietly([
        'tax_total' => $invoice->lineItems->first()->calculateTaxTotalAmount(),
    ]);

    $invoice->updateQuietly([
        'subtotal' => $subtotal,
        'profit_total' => $profitTotal,
        'tax_total' => $taxTotal,
        'total' => $total,
    ]);

    $invoice->refresh();
    $invoice->load(['lineItems.adjustments', 'client']);
    $invoice->approveDraft();
    $invoice->refresh();

    $receivableAccount = Account::getAccountsReceivableAccount($companyId);
    $approvalTransaction = $invoice->approvalTransaction;

    $arDebit = (int) JournalEntry::query()
        ->where('transaction_id', $approvalTransaction->id)
        ->where('account_id', $receivableAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');

    $expectedAr = $invoice->formatAmountToDefaultCurrency($total);
    $expectedTax = $invoice->formatAmountToDefaultCurrency($taxTotal);

    expect($arDebit)->toBe($expectedAr)
        ->and(sumApprovalSalesTaxCredits($invoice))->toBe($expectedTax)
        ->and($invoice->needsProfitJournalFix())->toBeFalse()
        ->and($invoice->journalTransactionIsBalanced($approvalTransaction))->toBeTrue();
});

test('fix invoice profit journals command repairs legacy duplicate tax journals', function () {
    $companyId = $this->testCompany->id;
    ensurePpnWapuConfigurationForCompany($companyId);

    $defaultCurrency = CurrencyAccessor::getDefaultCurrency();
    $subtotal = 1_000_000_000;
    $profitTotal = 100_000_000;
    $taxTotal = 121_000_000;
    $total = $subtotal + $profitTotal + $taxTotal;

    $invoice = Invoice::factory()
        ->withLineItems(1)
        ->state([
            'company_id' => $companyId,
            'currency_code' => $defaultCurrency,
            'due_date' => now()->addDays(30),
            'profit_total' => $profitTotal,
            'tax_total' => $taxTotal,
            'discount_method' => \App\Enums\Accounting\DocumentDiscountMethod::PerLineItem,
            'discount_total' => 0,
        ])
        ->create();

    $invoice->refresh();
    $invoice->lineItems->first()->updateQuietly([
        'subtotal' => $subtotal,
        'unit_price' => $subtotal,
        'quantity' => 1,
    ]);

    $wapuAdjustment = Adjustment::query()
        ->where('company_id', $companyId)
        ->where('name', 'PPN WAPU')
        ->first();
    $invoice->lineItems->first()->adjustments()->sync([$wapuAdjustment->id]);
    $invoice->lineItems->first()->updateQuietly([
        'tax_total' => $invoice->lineItems->first()->calculateTaxTotalAmount(),
    ]);

    $invoice->updateQuietly([
        'subtotal' => $subtotal,
        'profit_total' => $profitTotal,
        'tax_total' => $taxTotal,
        'total' => $total,
    ]);

    $invoice->refresh();
    $invoice->load(['lineItems.adjustments', 'client']);
    $invoice->approveDraft();
    $invoice->refresh();

    $receivableAccount = Account::getAccountsReceivableAccount($companyId);
    $wapuAccount = Account::getAccountsWapu($companyId);
    $approvalTransaction = $invoice->approvalTransaction;

    $legacyTaxOvercredit = $taxTotal - (int) round($subtotal * 0.11);

    JournalEntry::query()
        ->where('transaction_id', $approvalTransaction->id)
        ->where('account_id', $receivableAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->update(['amount' => $invoice->formatAmountToDefaultCurrency($total) + $legacyTaxOvercredit]);

    JournalEntry::query()
        ->where('transaction_id', $approvalTransaction->id)
        ->where('account_id', $wapuAccount->id)
        ->where('type', JournalEntryType::Credit)
        ->update(['amount' => $invoice->formatAmountToDefaultCurrency($taxTotal) + $legacyTaxOvercredit]);

    $invoice->refresh();
    expect($invoice->needsProfitJournalFix())->toBeTrue();

    session(['current_company_id' => $companyId]);

    $this->artisan('fix:invoice-profit-journals', [
        '--invoice-id' => $invoice->id,
    ])->assertSuccessful();

    $invoice->refresh();
    $approvalTransaction = $invoice->approvalTransaction;

    $arDebit = (int) JournalEntry::query()
        ->where('transaction_id', $approvalTransaction->id)
        ->where('account_id', $receivableAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');

    $expectedAr = $invoice->formatAmountToDefaultCurrency($total);
    $expectedTax = $invoice->formatAmountToDefaultCurrency($taxTotal);

    expect($arDebit)->toBe($expectedAr)
        ->and(sumApprovalSalesTaxCredits($invoice))->toBe($expectedTax)
        ->and($invoice->needsProfitJournalFix())->toBeFalse();
});

it('does not treat invoice above 10jt as WAPU when PPN WAPU adjustment is not configured', function () {
    $subtotal = 10_000_000_000;
    $taxTotal = 1_100_000_000;

    $invoice = new Invoice([
        'company_id' => $this->testCompany->id,
        'subtotal' => $subtotal,
        'tax_total' => $taxTotal,
        'profit_total' => 0,
        'discount_total' => 0,
        'total' => $subtotal + $taxTotal,
    ]);

    expect($invoice->exceedsWapuThreshold())->toBeTrue()
        ->and($invoice->isWapu())->toBeFalse()
        ->and(Adjustment::findPpnWapuForCompany($this->testCompany->id))->toBeNull();
});

it('treats invoice above 10jt as WAPU when PPN WAPU adjustment is configured', function () {
    Adjustment::factory()->salesTax('PPN WAPU')->create([
        'company_id' => $this->testCompany->id,
        'name' => 'PPN WAPU',
    ]);

    $subtotal = 10_000_000_000;
    $taxTotal = 1_100_000_000;

    $invoice = new Invoice([
        'company_id' => $this->testCompany->id,
        'subtotal' => $subtotal,
        'tax_total' => $taxTotal,
        'profit_total' => 0,
        'discount_total' => 0,
        'total' => $subtotal + $taxTotal,
    ]);

    expect($invoice->exceedsWapuThreshold())->toBeTrue()
        ->and($invoice->isWapu())->toBeTrue()
        ->and(Adjustment::findPpnWapuForCompany($this->testCompany->id))->not->toBeNull();
});
