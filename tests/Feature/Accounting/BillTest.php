<?php

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountDefaultKey;
use App\Enums\Accounting\BillStatus;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\JournalEntryType;
use App\Enums\Accounting\PaymentMethod;
use App\Models\AccountDefault;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Models\Accounting\Bill;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
use App\Models\Banking\BankAccount;
use App\Scopes\CurrentCompanyScope;
use App\Utilities\Currency\CurrencyAccessor;

beforeEach(function () {
    $this->withOfferings();
    ensureBillAccountDefaultsForCompany($this->testCompany->id);
});

function ensureBillAccountDefaultsForCompany(int $companyId): void
{
    $defaultCurrency = CurrencyAccessor::getDefaultCurrency();

    $liabilitySubtype = AccountSubtype::query()
        ->where('company_id', $companyId)
        ->where('category', AccountCategory::Liability)
        ->first();

    $assetSubtype = AccountSubtype::query()
        ->where('company_id', $companyId)
        ->where('category', AccountCategory::Asset)
        ->first();

    $liabilitySubtypeId = $liabilitySubtype?->id ?? AccountSubtype::query()
        ->where('company_id', $companyId)
        ->value('id');

    $assetSubtypeId = $assetSubtype?->id ?? $liabilitySubtypeId;

    $defaultsToEnsure = [
        AccountDefaultKey::AccountsPayable->value => [
            'name' => 'Accounts Payable (Test)',
            'subtype_id' => $liabilitySubtypeId,
        ],
        AccountDefaultKey::PPh23->value => [
            'name' => 'Prepaid PPh 23 (Test)',
            'subtype_id' => $assetSubtypeId,
        ],
        AccountDefaultKey::PPh23Payable->value => [
            'name' => 'Hutang PPh 23 (Test)',
            'subtype_id' => $liabilitySubtypeId,
        ],
    ];

    foreach ($defaultsToEnsure as $key => $config) {
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
            'subtype_id' => $config['subtype_id'],
            'name' => $config['name'],
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

test('record payment posts pph23 to hutang pph23 payable account', function () {
    $companyId = $this->testCompany->id;

    $bill = Bill::factory()
        ->withLineItems()
        ->initialized()
        ->state([
            'company_id' => $companyId,
            'due_date' => now()->addDays(30),
            'discount_method' => DocumentDiscountMethod::PerLineItem,
        ])
        ->create();

    $bill->refresh();

    $payableAccount = Account::getAccountsPPh23Payable($companyId);
    $prepaidAccount = Account::getAccountsPPh23($companyId);

    expect($payableAccount->id)->not->toBe($prepaidAccount->id);

    $manualPph23 = max((int) $bill->pph23 - 50_000, 1_000);

    $bankAccount = BankAccount::query()
        ->where('company_id', $companyId)
        ->where('enabled', true)
        ->first();

    expect($bankAccount)->not->toBeNull();

    $bill->update(['pph23' => $manualPph23]);
    $bill->refresh();

    $paymentAmount = (int) $bill->amount_due;
    expect($paymentAmount)->toBeGreaterThan(0);

    $bill->recordPayment([
        'posted_at' => now()->toDateString(),
        'payment_method' => PaymentMethod::BankPayment,
        'bank_account_id' => $bankAccount->id,
        'amount' => $paymentAmount,
        'pph23' => $manualPph23,
        'notes' => 'Bill PPh23 payable test',
    ]);

    $bill->refresh();

    expect($bill->status)->toBe(BillStatus::Paid)
        ->and((int) $bill->pph23)->toBe($manualPph23);

    $paymentTrx = Transaction::query()
        ->where('transactionable_type', Bill::class)
        ->where('transactionable_id', $bill->id)
        ->where('is_payment', true)
        ->orderByDesc('id')
        ->first();

    expect($paymentTrx)->not->toBeNull()
        ->and((int) ($paymentTrx->meta['pph23_cents'] ?? 0))->toBe($manualPph23);

    $payableCredit = (int) JournalEntry::query()
        ->where('transaction_id', $paymentTrx->id)
        ->where('account_id', $payableAccount->id)
        ->where('type', JournalEntryType::Credit)
        ->sum('amount');

    $prepaidCredit = (int) JournalEntry::query()
        ->where('transaction_id', $paymentTrx->id)
        ->where('account_id', $prepaidAccount->id)
        ->where('type', JournalEntryType::Credit)
        ->sum('amount');

    expect($payableCredit)->toBe($manualPph23)
        ->and($prepaidCredit)->toBe(0);
});

test('record payment with lowered pph23 realigns amount and posts manual hutang pph23', function () {
    $companyId = $this->testCompany->id;

    $bill = Bill::factory()
        ->withLineItems()
        ->initialized()
        ->state([
            'company_id' => $companyId,
            'due_date' => now()->addDays(30),
            'discount_method' => DocumentDiscountMethod::PerLineItem,
        ])
        ->create();

    $bill->refresh();

    $defaultPph23 = (int) $bill->pph23;
    if ($defaultPph23 <= 50_000) {
        $defaultPph23 = 100_000;
        $bill->update(['pph23' => $defaultPph23]);
        $bill->refresh();
    }

    $manualPph23 = $defaultPph23 - 50_000;
    expect($manualPph23)->toBeLessThan($defaultPph23);

    $bankAccount = BankAccount::query()
        ->where('company_id', $companyId)
        ->where('enabled', true)
        ->first();

    expect($bankAccount)->not->toBeNull();

    // Simulate form submit where Amount still equals the old amount_due (before PPh 23 change).
    $staleAmountDue = (int) $bill->amount_due;
    $expectedCash = max((int) $bill->total - $manualPph23, 0);

    $bill->recordPaymentWithPph23([
        'posted_at' => now()->toDateString(),
        'payment_method' => PaymentMethod::BankPayment,
        'bank_account_id' => $bankAccount->id,
        'amount' => $staleAmountDue,
        'pph23' => $manualPph23,
        'notes' => 'Lowered PPh23 full pay',
    ]);

    $bill->refresh();

    expect($bill->status)->toBe(BillStatus::Paid)
        ->and((int) $bill->pph23)->toBe($manualPph23)
        ->and((int) $bill->amount_paid)->toBe($expectedCash);

    $paymentTrx = Transaction::query()
        ->where('transactionable_type', Bill::class)
        ->where('transactionable_id', $bill->id)
        ->where('is_payment', true)
        ->orderByDesc('id')
        ->first();

    $payableAccount = Account::getAccountsPPh23Payable($companyId);

    $payableCredit = (int) JournalEntry::query()
        ->where('transaction_id', $paymentTrx->id)
        ->where('account_id', $payableAccount->id)
        ->where('type', JournalEntryType::Credit)
        ->sum('amount');

    expect($paymentTrx)->not->toBeNull()
        ->and((int) ($paymentTrx->meta['pph23_cents'] ?? 0))->toBe($manualPph23)
        ->and((int) ($paymentTrx->meta['amount_in_document_currency_cents'] ?? 0))->toBe($expectedCash)
        ->and($payableCredit)->toBe($manualPph23);
});

test('record payment with raised pph23 realigns amount and posts manual hutang pph23', function () {
    $companyId = $this->testCompany->id;

    $bill = Bill::factory()
        ->withLineItems()
        ->initialized()
        ->state([
            'company_id' => $companyId,
            'due_date' => now()->addDays(30),
            'discount_method' => DocumentDiscountMethod::PerLineItem,
        ])
        ->create();

    $bill->refresh();

    $defaultPph23 = (int) $bill->pph23;
    $manualPph23 = $defaultPph23 + 50_000;
    expect($manualPph23)->toBeLessThan((int) $bill->total);

    $bankAccount = BankAccount::query()
        ->where('company_id', $companyId)
        ->where('enabled', true)
        ->first();

    expect($bankAccount)->not->toBeNull();

    $staleAmountDue = (int) $bill->amount_due;
    $expectedCash = max((int) $bill->total - $manualPph23, 0);

    $bill->recordPaymentWithPph23([
        'posted_at' => now()->toDateString(),
        'payment_method' => PaymentMethod::BankPayment,
        'bank_account_id' => $bankAccount->id,
        'amount' => $staleAmountDue,
        'pph23' => $manualPph23,
        'notes' => 'Raised PPh23 full pay',
    ]);

    $bill->refresh();

    expect($bill->status)->toBe(BillStatus::Paid)
        ->and((int) $bill->pph23)->toBe($manualPph23)
        ->and((int) $bill->amount_paid)->toBe($expectedCash);

    $paymentTrx = Transaction::query()
        ->where('transactionable_type', Bill::class)
        ->where('transactionable_id', $bill->id)
        ->where('is_payment', true)
        ->orderByDesc('id')
        ->first();

    $payableAccount = Account::getAccountsPPh23Payable($companyId);

    $payableCredit = (int) JournalEntry::query()
        ->where('transaction_id', $paymentTrx->id)
        ->where('account_id', $payableAccount->id)
        ->where('type', JournalEntryType::Credit)
        ->sum('amount');

    expect($paymentTrx)->not->toBeNull()
        ->and((int) ($paymentTrx->meta['pph23_cents'] ?? 0))->toBe($manualPph23)
        ->and((int) ($paymentTrx->meta['amount_in_document_currency_cents'] ?? 0))->toBe($expectedCash)
        ->and($payableCredit)->toBe($manualPph23);
});

test('record payment with zero pph23 pays full total and skips hutang pph23 journal', function () {
    $companyId = $this->testCompany->id;

    $bill = Bill::factory()
        ->withLineItems()
        ->initialized()
        ->state([
            'company_id' => $companyId,
            'due_date' => now()->addDays(30),
            'discount_method' => DocumentDiscountMethod::PerLineItem,
        ])
        ->create();

    $bill->refresh();

    expect((int) $bill->pph23)->toBeGreaterThan(0);

    $bankAccount = BankAccount::query()
        ->where('company_id', $companyId)
        ->where('enabled', true)
        ->first();

    expect($bankAccount)->not->toBeNull();

    $billTotal = (int) $bill->total;

    // User sets Amount = full total and PPh 23 = 0 (explicit), even if form still had old amount_due.
    $bill->recordPaymentWithPph23([
        'posted_at' => now()->toDateString(),
        'payment_method' => PaymentMethod::BankPayment,
        'bank_account_id' => $bankAccount->id,
        'amount' => $billTotal,
        'pph23' => 0,
        'notes' => 'Zero PPh23 full pay',
    ]);

    $bill->refresh();

    expect($bill->status)->toBe(BillStatus::Paid)
        ->and((int) $bill->pph23)->toBe(0)
        ->and((int) $bill->actual_total)->toBe($billTotal)
        ->and((int) $bill->amount_paid)->toBe($billTotal);

    $paymentTrx = Transaction::query()
        ->where('transactionable_type', Bill::class)
        ->where('transactionable_id', $bill->id)
        ->where('is_payment', true)
        ->orderByDesc('id')
        ->first();

    $payableAccount = Account::getAccountsPPh23Payable($companyId);

    $payableCredit = (int) JournalEntry::query()
        ->where('transaction_id', $paymentTrx->id)
        ->where('account_id', $payableAccount->id)
        ->where('type', JournalEntryType::Credit)
        ->sum('amount');

    expect($paymentTrx)->not->toBeNull()
        ->and(array_key_exists('pph23_cents', $paymentTrx->meta ?? []))->toBeTrue()
        ->and((int) $paymentTrx->meta['pph23_cents'])->toBe(0)
        ->and((int) ($paymentTrx->meta['amount_in_document_currency_cents'] ?? 0))->toBe($billTotal)
        ->and($payableCredit)->toBe(0);
});

test('bill payment without pph23 payable default throws', function () {
    $companyId = $this->testCompany->id;

    $bill = Bill::factory()
        ->withLineItems()
        ->initialized()
        ->state([
            'company_id' => $companyId,
            'due_date' => now()->addDays(30),
            'discount_method' => DocumentDiscountMethod::PerLineItem,
        ])
        ->create();

    $bill->refresh();

    $bankAccount = BankAccount::query()
        ->where('company_id', $companyId)
        ->where('enabled', true)
        ->first();

    expect($bankAccount)->not->toBeNull();

    $partialAmount = (int) floor($bill->amount_due / 2);
    expect($partialAmount)->toBeGreaterThan(0);

    $bill->recordPayment([
        'posted_at' => now()->toDateString(),
        'payment_method' => PaymentMethod::BankPayment,
        'bank_account_id' => $bankAccount->id,
        'amount' => $partialAmount,
        'pph23' => (int) $bill->pph23,
    ]);

    $bill->refresh();
    expect($bill->status)->toBe(BillStatus::Partial);

    AccountDefault::query()
        ->withoutGlobalScope(CurrentCompanyScope::class)
        ->where('company_id', $companyId)
        ->where('key', AccountDefaultKey::PPh23Payable->value)
        ->delete();

    expect(fn () => $bill->recordPayment([
        'posted_at' => now()->toDateString(),
        'payment_method' => PaymentMethod::BankPayment,
        'bank_account_id' => $bankAccount->id,
        'amount' => (int) $bill->amount_due,
        'pph23' => (int) $bill->pph23,
    ]))->toThrow(\RuntimeException::class, 'pph_23_payable');
});
