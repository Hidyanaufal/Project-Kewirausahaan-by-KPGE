<?php

use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountDefaultKey;
use App\Enums\Accounting\JournalEntryType;
use App\Enums\Accounting\PaymentMethod;
use App\Models\AccountDefault;
use App\Models\Accounting\Account;
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
});

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
            'due_date' => now()->addDays(30),
        ])
        ->create();

    $invoice->refresh();

    $invoice->approveDraft();

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
            'due_date' => now()->subDays(30),
        ])
        ->create();

    $invoice->refresh();

    $invoice->approveDraft();

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
