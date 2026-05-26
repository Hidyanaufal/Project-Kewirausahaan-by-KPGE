<?php

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountDefaultKey;
use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Accounting\JournalEntryType;
use App\Enums\Accounting\PaymentMethod;
use App\Models\AccountDefault;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Models\Accounting\Adjustment;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
use App\Models\Banking\BankAccount;
use App\Scopes\CurrentCompanyScope;
use App\Utilities\Currency\CurrencyAccessor;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

beforeEach(function () {
    $this->withOfferings();
    ensureInvoicePaymentFixAccountDefaultsForCompany($this->testCompany->id);
});

function ensureInvoicePaymentFixAccountDefaultsForCompany(int $companyId): void
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

function removePpnWapuPaymentFixConfigurationForCompany(int $companyId): void
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

function ensurePpnWapuPaymentFixConfigurationForCompany(int $companyId): Account
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

function attachElevenPercentPpnToPaymentFixInvoiceLineItem(Invoice $invoice): void
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

function createHistoricalBrokenNonWapuPaidInvoice(int $companyId): array
{
    $wapuAccount = ensurePpnWapuPaymentFixConfigurationForCompany($companyId);

    $defaultCurrency = CurrencyAccessor::getDefaultCurrency();
    $subtotal = 1_000_000;
    $taxTotal = 110_000;
    $total = $subtotal + $taxTotal;
    $manualPph23 = 20_000;

    $invoice = Invoice::factory()
        ->withLineItems(1)
        ->state([
            'company_id' => $companyId,
            'currency_code' => $defaultCurrency,
            'due_date' => now()->addDays(30),
            'profit_total' => 0,
            'discount_method' => DocumentDiscountMethod::PerLineItem,
            'discount_rate' => 0,
            'discount_total' => 0,
        ])
        ->create();

    $invoice->refresh();
    $lineItem = $invoice->lineItems->first();
    $lineItem->updateQuietly([
        'subtotal' => $subtotal,
        'unit_price' => $subtotal,
        'quantity' => 1,
    ]);
    attachElevenPercentPpnToPaymentFixInvoiceLineItem($invoice);
    $invoice->updateQuietly([
        'subtotal' => $subtotal,
        'profit_total' => 0,
        'discount_total' => 0,
        'tax_total' => $taxTotal,
        'total' => $total,
    ]);

    $invoice->refresh();
    $invoice->load(['lineItems.adjustments', 'client']);
    $invoice->approveDraft();
    $invoice->refresh();
    $invoice->update(['pph23' => $manualPph23]);
    $invoice->refresh();

    $bankAccount = BankAccount::query()
        ->where('company_id', $invoice->company_id)
        ->where('enabled', true)
        ->firstOrFail();

    $invoice->recordPayment([
        'posted_at' => now()->toDateString(),
        'payment_method' => PaymentMethod::BankPayment,
        'bank_account_id' => $bankAccount->id,
        'amount' => (int) $invoice->actual_total,
        'pph23' => $manualPph23,
        'notes' => 'Historical non-WAPU payment journal test',
    ]);

    $invoice->refresh();
    $payment = $invoice->payments()->latest('id')->firstOrFail();
    $ppnAccount = Account::getAccountsPPn($companyId);
    $bankDebit = JournalEntry::query()
        ->where('transaction_id', $payment->id)
        ->where('account_id', $payment->bankAccount->account_id)
        ->where('type', JournalEntryType::Debit)
        ->firstOrFail();

    $bankDebit->update([
        'amount' => (int) $bankDebit->amount - $taxTotal,
    ]);

    JournalEntry::query()->create([
        'company_id' => $companyId,
        'transaction_id' => $payment->id,
        'account_id' => $ppnAccount->id,
        'type' => JournalEntryType::Debit,
        'amount' => $taxTotal,
        'description' => 'Expected PPN payment entry',
    ]);

    JournalEntry::query()->create([
        'company_id' => $companyId,
        'transaction_id' => $payment->id,
        'account_id' => $wapuAccount->id,
        'type' => JournalEntryType::Debit,
        'amount' => $taxTotal,
        'description' => 'Legacy duplicated WAPU payment entry',
    ]);

    return [$invoice->fresh(['payments.bankAccount']), $payment->fresh()];
}

function createHistoricalBrokenWapuPaidInvoice(int $companyId): array
{
    ensurePpnWapuPaymentFixConfigurationForCompany($companyId);

    $defaultCurrency = CurrencyAccessor::getDefaultCurrency();
    $subtotal = 1_000_000_000;
    $profitTotal = 100_000_000;
    $taxTotal = 121_000_000;
    $total = $subtotal + $profitTotal + $taxTotal;
    $manualPph23 = 22_000_000;

    $invoice = Invoice::factory()
        ->withLineItems(1)
        ->state([
            'company_id' => $companyId,
            'currency_code' => $defaultCurrency,
            'due_date' => now()->addDays(30),
            'profit_total' => $profitTotal,
            'discount_method' => DocumentDiscountMethod::PerLineItem,
            'discount_rate' => 0,
            'discount_total' => 0,
        ])
        ->create();

    $invoice->refresh();
    $lineItem = $invoice->lineItems->first();
    $lineItem->updateQuietly([
        'subtotal' => $subtotal,
        'unit_price' => $subtotal,
        'quantity' => 1,
    ]);

    $wapuAdjustment = Adjustment::query()
        ->where('company_id', $companyId)
        ->where('name', 'PPN WAPU')
        ->firstOrFail();

    $lineItem->adjustments()->sync([$wapuAdjustment->id]);
    $lineItem->updateQuietly([
        'tax_total' => $lineItem->calculateTaxTotalAmount(),
    ]);

    $invoice->updateQuietly([
        'subtotal' => $subtotal,
        'profit_total' => $profitTotal,
        'discount_total' => 0,
        'tax_total' => $taxTotal,
        'total' => $total,
    ]);

    $invoice->refresh();
    $invoice->load(['lineItems.adjustments', 'client']);
    $invoice->approveDraft();
    $invoice->refresh();
    $invoice->update(['pph23' => $manualPph23]);
    $invoice->refresh();

    $bankAccount = BankAccount::query()
        ->where('company_id', $invoice->company_id)
        ->where('enabled', true)
        ->firstOrFail();

    $invoice->recordPayment([
        'posted_at' => now()->toDateString(),
        'payment_method' => PaymentMethod::BankPayment,
        'bank_account_id' => $bankAccount->id,
        'amount' => (int) $invoice->actual_total,
        'pph23' => $manualPph23,
        'notes' => 'Healthy WAPU payment journal test',
    ]);

    $payment = $invoice->payments()->latest('id')->firstOrFail();
    $ppnAccount = Account::getAccountsPPn($companyId);

    JournalEntry::query()->create([
        'company_id' => $companyId,
        'transaction_id' => $payment->id,
        'account_id' => $ppnAccount->id,
        'type' => JournalEntryType::Debit,
        'amount' => $taxTotal,
        'description' => 'Legacy duplicated PPN payment entry',
    ]);

    return [$invoice->fresh(['payments.bankAccount']), $payment->fresh()];
}

it('requires company id when dry running historical payment ppn fix', function () {
    $this->artisan('fix:invoice-payment-ppn-journals', [
        '--dry-run' => true,
    ])->assertExitCode(SymfonyCommand::FAILURE);
});

it('dry runs historical payment ppn fix per company without changing journals', function () {
    [$invoice, $payment] = createHistoricalBrokenNonWapuPaidInvoice($this->testCompany->id);

    $ppnAccount = Account::getAccountsPPn($invoice->company_id);
    $wapuAccount = Account::getAccountsWapu($invoice->company_id);
    $ppnDebitBefore = (int) JournalEntry::query()
        ->where('transaction_id', $payment->id)
        ->where('account_id', $ppnAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');
    $wapuDebitBefore = (int) JournalEntry::query()
        ->where('transaction_id', $payment->id)
        ->where('account_id', $wapuAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->needsHistoricalPaymentTaxAccountFix())->toBeTrue()
        ->and($invoice->journalTransactionIsBalanced($payment))->toBeFalse();

    $this->artisan('fix:invoice-payment-ppn-journals', [
        '--dry-run' => true,
        '--company-id' => $invoice->company_id,
    ])
        ->expectsOutputToContain('DRY-RUN MODE')
        ->expectsOutputToContain($invoice->invoice_number)
        ->assertSuccessful();

    $payment->refresh();

    $ppnDebitAfter = (int) JournalEntry::query()
        ->where('transaction_id', $payment->id)
        ->where('account_id', $ppnAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');
    $wapuDebitAfter = (int) JournalEntry::query()
        ->where('transaction_id', $payment->id)
        ->where('account_id', $wapuAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');

    expect($ppnDebitAfter)->toBe($ppnDebitBefore)
        ->and($wapuDebitAfter)->toBe($wapuDebitBefore)
        ->and($invoice->fresh()->needsHistoricalPaymentTaxAccountFix())->toBeTrue()
        ->and($invoice->fresh()->journalTransactionIsBalanced($payment->fresh()))->toBeFalse();
});

it('fixes historical payment ppn journals by keeping only the expected tax account', function () {
    [$brokenInvoice, $brokenPayment] = createHistoricalBrokenNonWapuPaidInvoice($this->testCompany->id);
    [$wapuInvoice, $wapuPayment] = createHistoricalBrokenWapuPaidInvoice($this->testCompany->id);

    $ppnAccount = Account::getAccountsPPn($brokenInvoice->company_id);
    $brokenWapuAccount = Account::getAccountsWapu($brokenInvoice->company_id);
    $wapuAccount = Account::getAccountsWapu($wapuInvoice->company_id);

    expect($brokenInvoice->needsHistoricalPaymentTaxAccountFix())->toBeTrue()
        ->and($brokenInvoice->journalTransactionIsBalanced($brokenPayment))->toBeFalse();

    $brokenPpnDebitBefore = (int) JournalEntry::query()
        ->where('transaction_id', $brokenPayment->id)
        ->where('account_id', $ppnAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');
    $brokenWapuDebitBefore = (int) JournalEntry::query()
        ->where('transaction_id', $brokenPayment->id)
        ->where('account_id', $brokenWapuAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');
    $wapuPpnDebitBefore = (int) JournalEntry::query()
        ->where('transaction_id', $wapuPayment->id)
        ->where('account_id', $ppnAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');
    $wapuDebitBefore = (int) JournalEntry::query()
        ->where('transaction_id', $wapuPayment->id)
        ->where('account_id', $wapuAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');

    $this->artisan('fix:invoice-payment-ppn-journals', [
        '--all-companies' => true,
    ])
        ->expectsOutputToContain($brokenInvoice->invoice_number)
        ->assertSuccessful();

    $brokenInvoice->refresh()->load('payments.bankAccount');
    $brokenPayment = $brokenInvoice->payments()->latest('id')->firstOrFail();
    $wapuPayment->refresh();

    $brokenPpnDebitAfter = (int) JournalEntry::query()
        ->where('transaction_id', $brokenPayment->id)
        ->where('account_id', $ppnAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');
    $brokenWapuDebitAfter = (int) JournalEntry::query()
        ->where('transaction_id', $brokenPayment->id)
        ->where('account_id', $brokenWapuAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');

    $wapuPpnDebitAfter = (int) JournalEntry::query()
        ->where('transaction_id', $wapuPayment->id)
        ->where('account_id', $ppnAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');
    $wapuDebitAfter = (int) JournalEntry::query()
        ->where('transaction_id', $wapuPayment->id)
        ->where('account_id', $wapuAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');

    expect($brokenPpnDebitBefore)->toBeGreaterThan(0)
        ->and($brokenWapuDebitBefore)->toBeGreaterThan(0)
        ->and($wapuPpnDebitBefore)->toBeGreaterThan(0)
        ->and($wapuDebitBefore)->toBeGreaterThan(0)
        ->and($brokenPpnDebitAfter)->toBe($brokenPpnDebitBefore)
        ->and($brokenWapuDebitAfter)->toBe(0)
        ->and($brokenInvoice->needsHistoricalPaymentTaxAccountFix())->toBeFalse()
        ->and($brokenInvoice->journalTransactionIsBalanced($brokenPayment))->toBeTrue()
        ->and($wapuPpnDebitAfter)->toBe(0)
        ->and($wapuDebitAfter)->toBe($wapuDebitBefore)
        ->and($wapuInvoice->fresh()->journalTransactionIsBalanced($wapuPayment))->toBeTrue();
});
