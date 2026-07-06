<?php

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountDefaultKey;
use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\DocumentDiscountMethod;
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
use App\Services\LegacyImportedInvoiceJournalService;
use App\Utilities\Currency\CurrencyAccessor;

beforeEach(function () {
    $this->withOfferings();
    ensureLegacyImportJournalTestAccountDefaults($this->testCompany->id);
    ensureLegacyImportPpnWapuConfigurationForCompany($this->testCompany->id);
});

it('consolidates split legacy approval tax before standard payment journals balance', function () {
    $companyId = $this->testCompany->id;
    $service = app(LegacyImportedInvoiceJournalService::class);

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

    $approvalTrx = $invoice->approvalTransaction;
    $ppnAccount = Account::getAccountsPPn($companyId);
    $wapuAccount = Account::getAccountsWapu($companyId);

    $wapuApprovalEntry = JournalEntry::query()
        ->where('transaction_id', $approvalTrx->id)
        ->where('account_id', $wapuAccount->id)
        ->where('type', JournalEntryType::Credit)
        ->firstOrFail();

    $splitAmount = 411_840;
    $wapuApprovalEntry->update([
        'amount' => (int) $wapuApprovalEntry->amount - $splitAmount,
    ]);

    JournalEntry::query()->create([
        'company_id' => $companyId,
        'transaction_id' => $approvalTrx->id,
        'account_id' => $ppnAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => $splitAmount,
        'description' => 'Legacy split PPN approval entry',
    ]);

    $service->preparePaidInvoiceForLegacyImport($invoice->fresh());

    $approvalPpnAfter = (int) JournalEntry::query()
        ->where('transaction_id', $approvalTrx->id)
        ->where('account_id', $ppnAccount->id)
        ->where('type', JournalEntryType::Credit)
        ->sum('amount');
    $approvalWapuAfter = (int) JournalEntry::query()
        ->where('transaction_id', $approvalTrx->id)
        ->where('account_id', $wapuAccount->id)
        ->where('type', JournalEntryType::Credit)
        ->sum('amount');

    expect($approvalPpnAfter)->toBe(0)
        ->and($approvalWapuAfter)->toBe($taxTotal);

    $bankAccount = BankAccount::query()
        ->where('company_id', $companyId)
        ->where('enabled', true)
        ->firstOrFail();

    $invoice->recordPayment([
        'posted_at' => now()->toDateString(),
        'payment_method' => PaymentMethod::BankPayment,
        'bank_account_id' => $bankAccount->id,
        'amount' => (int) $invoice->actual_total,
        'pph23' => $manualPph23,
        'notes' => 'Legacy import payment journal test',
    ]);

    $paymentTrx = Transaction::query()
        ->where('transactionable_type', Invoice::class)
        ->where('transactionable_id', $invoice->id)
        ->where('is_payment', true)
        ->orderByDesc('id')
        ->firstOrFail();

    $paymentPpnDebit = (int) JournalEntry::query()
        ->where('transaction_id', $paymentTrx->id)
        ->where('account_id', $ppnAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');
    $paymentWapuDebit = (int) JournalEntry::query()
        ->where('transaction_id', $paymentTrx->id)
        ->where('account_id', $wapuAccount->id)
        ->where('type', JournalEntryType::Debit)
        ->sum('amount');

    expect($paymentPpnDebit)->toBe(0)
        ->and($paymentWapuDebit)->toBe($taxTotal)
        ->and($invoice->fresh()->journalTransactionIsBalanced($paymentTrx))->toBeTrue();
});

function ensureLegacyImportJournalTestAccountDefaults(int $companyId): void
{
    $defaultCurrency = CurrencyAccessor::getDefaultCurrency();
    $assetSubtype = AccountSubtype::query()
        ->where('company_id', $companyId)
        ->where('category', AccountCategory::Asset)
        ->first();

    $subtypeId = $assetSubtype?->id ?? AccountSubtype::query()
        ->where('company_id', $companyId)
        ->value('id');

    foreach ([
        AccountDefaultKey::PPh23->value => 'PPh 23 (Legacy Journal Test)',
        AccountDefaultKey::PPn->value => 'PPN (Legacy Journal Test)',
        AccountDefaultKey::Profit->value => 'Profit (Legacy Journal Test)',
        AccountDefaultKey::AccountsReceivable->value => 'Accounts Receivable (Legacy Journal Test)',
        AccountDefaultKey::SalesDiscount->value => 'Sales Discount (Legacy Journal Test)',
    ] as $key => $accountName) {
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
        ]);

        AccountDefault::query()->create([
            'company_id' => $companyId,
            'account_id' => $account->id,
            'key' => $key,
        ]);
    }
}

function ensureLegacyImportPpnWapuConfigurationForCompany(int $companyId): Account
{
    $existing = Account::getAccountsWapuIfConfigured($companyId);
    if ($existing) {
        return $existing;
    }

    $defaultCurrency = CurrencyAccessor::getDefaultCurrency();
    $assetSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('company_id', $companyId)
        ->where('category', \App\Enums\Accounting\AccountCategory::Asset)
        ->first();

    $wapuAccount = Account::factory()->create([
        'company_id' => $companyId,
        'subtype_id' => $assetSubtype?->id,
        'name' => 'PPN WAPU (Legacy Import Test)',
        'currency_code' => $defaultCurrency,
    ]);

    \App\Models\AccountDefault::query()->create([
        'company_id' => $companyId,
        'account_id' => $wapuAccount->id,
        'key' => \App\Enums\Accounting\AccountDefaultKey::PPnWapu->value,
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
