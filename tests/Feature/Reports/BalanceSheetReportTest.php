<?php

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountDefaultKey;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryType;
use App\Facades\Reporting;
use App\Models\AccountDefault;
use App\Models\Accounting\Account;
use App\Models\Accounting\Transaction;
use App\Services\AccountService;
use App\Services\ReportService;
use App\Transformers\BalanceSheetReportTransformer;
use App\Utilities\Currency\CurrencyConverter;
use Carbon\Carbon;

it('calculates contra equity net movement using account type normal balance', function () {
    $account = Account::factory()->make([
        'category' => AccountCategory::Equity,
        'type' => AccountType::ContraEquity,
    ]);
    $account->total_debit = 10_000;
    $account->total_credit = 0;
    $account->starting_balance = 0;

    $balances = Reporting::calculateAccountBalances($account);

    expect($balances['net_movement'])->toBe(10_000)
        ->and($balances['ending_balance'])->toBe(10_000);
});

it('does not park computed equity in a misleading Total Other Equity summary row', function () {
    $company = $this->testCompany;
    $asOf = Carbon::now()->toDateString();

    $report = Reporting::buildBalanceSheetReport($asOf, [], null, $company->id);
    $summaryCategories = (new BalanceSheetReportTransformer($report))->getSummaryCategories();

    $equityTypes = $summaryCategories['Equity']->types ?? [];

    expect(array_key_exists('Total Other Equity', $equityTypes))->toBeFalse();
});

it('detects parent accounts for chart of accounts hierarchy', function () {
    $company = $this->testCompany;

    $referenceEquity = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::Equity)
        ->firstOrFail();

    $parent = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $referenceEquity->subtype_id,
        'code' => '9998',
        'name' => 'BS Test Parent Equity',
    ]);

    $child = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $referenceEquity->subtype_id,
        'parent_id' => $parent->id,
        'code' => '9998.01',
        'name' => 'BS Test Child Equity',
    ]);

    expect($parent->fresh()->isParentAccount())->toBeTrue()
        ->and($child->fresh()->isParentAccount())->toBeFalse()
        ->and(Account::parentAccountIdsForCompany($company->id))->toContain($parent->id);
});

it('excludes parent account balances from balance sheet equity category totals', function () {
    $company = $this->testCompany;
    $asOf = Carbon::now()->toDateString();

    $referenceEquity = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::Equity)
        ->firstOrFail();

    $parent = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $referenceEquity->subtype_id,
        'code' => '9997',
        'name' => 'BS Parent Equity Aggregate',
    ]);

    $child = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $referenceEquity->subtype_id,
        'parent_id' => $parent->id,
        'code' => '9997.01',
        'name' => 'BS Child Equity Aggregate',
    ]);

    $equityBefore = CurrencyConverter::convertToFloat(
        Reporting::buildBalanceSheetReport($asOf, [], null, $company->id)
            ->categories['Equity']->summary->endingBalance ?? '0'
    );

    $amount = 250_000;
    $bankAccount = $company->default->bankAccount;

    $transaction = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $bankAccount->id,
        ])
        ->asJournal($amount * 2)
        ->create([
            'posted_at' => now()->toDateTimeString(),
        ]);

    $entryDefaults = [
        'company_id' => $company->id,
        'created_by' => $this->testUser->id,
        'updated_by' => $this->testUser->id,
    ];

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->account_id,
        'type' => JournalEntryType::Debit,
        'amount' => $amount * 2,
    ]));

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $child->id,
        'type' => JournalEntryType::Credit,
        'amount' => $amount,
    ]));

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $parent->id,
        'type' => JournalEntryType::Credit,
        'amount' => $amount,
    ]));

    $report = Reporting::buildBalanceSheetReport($asOf, [], null, $company->id);
    $equityAfter = CurrencyConverter::convertToFloat($report->categories['Equity']->summary->endingBalance ?? '0');

    $startDate = app(ReportService::class)->getEarliestTransactionDate();
    $accountService = app(AccountService::class);

    $childWithBalances = $accountService->getAccountBalances($startDate, $asOf, [$child->id], null, $company->id)->first();
    $parentWithBalances = $accountService->getAccountBalances($startDate, $asOf, [$parent->id], null, $company->id)->first();

    $childEnding = Reporting::calculateAccountBalances($childWithBalances)['ending_balance'] ?? 0;
    $parentEnding = Reporting::calculateAccountBalances($parentWithBalances)['ending_balance'] ?? 0;

    $equityDelta = $equityAfter - $equityBefore;
    $expectedDelta = $childEnding / 100;

    expect($parentEnding)->toBe($childEnding)
        ->and($equityDelta)->toBeGreaterThan(0)
        ->and(abs($equityDelta - $expectedDelta))->toBeLessThan(0.02)
        ->and(abs($equityDelta - (($childEnding + $parentEnding) / 100)))->toBeGreaterThan(0.02);
});

it('excludes open balance equity clearing account from balance sheet category totals', function () {
    $company = $this->testCompany;
    $asOf = Carbon::now()->toDateString();

    $referenceEquity = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::Equity)
        ->firstOrFail();

    $clearingAccount = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $referenceEquity->subtype_id,
        'code' => '9996',
        'name' => 'Test Open Balance Equity',
    ]);

    AccountDefault::query()->create([
        'company_id' => $company->id,
        'account_id' => $clearingAccount->id,
        'key' => AccountDefaultKey::OpenBalance,
    ]);

    expect(Account::balanceSheetExcludedAccountIdsForCompany($company->id))
        ->toContain($clearingAccount->id);

    $report = Reporting::buildBalanceSheetReport($asOf, [], null, $company->id);

    $equitySummary = CurrencyConverter::convertToFloat(
        $report->categories['Equity']->summary->endingBalance ?? '0'
    );

    $startDate = app(ReportService::class)->getEarliestTransactionDate();
    $accounts = app(AccountService::class)->getAccountBalances($startDate, $asOf, [], null, $company->id)->get();

    $expectedEquity = 0;
    $excludedIds = Account::balanceSheetExcludedAccountIdsForCompany($company->id)->flip();

    foreach ($accounts as $account) {
        if ($account->type->getCategory() !== AccountCategory::Equity || $excludedIds->has($account->id)) {
            continue;
        }

        $balances = Reporting::calculateAccountBalances($account);
        $expectedEquity += ($balances['ending_balance'] ?? 0) / 100;
    }

    $retainedEarnings = Reporting::calculateRetainedEarnings($startDate, $asOf, null, $company->id)->getAmount() / 100;

    expect(abs($equitySummary - ($expectedEquity + $retainedEarnings)))->toBeLessThan(0.02);
});

it('detects dedicated retained earnings GL account for balance sheet aggregation', function () {
    $company = $this->testCompany;

    $referenceEquity = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::Equity)
        ->firstOrFail();

    expect(Account::shouldAddComputedRetainedEarningsToBalanceSheet($company->id))->toBeTrue();

    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $referenceEquity->subtype_id,
        'code' => '3200.99',
        'name' => 'TEST RETAINED EARNING',
    ]);

    expect(Account::shouldAddComputedRetainedEarningsToBalanceSheet($company->id))->toBeFalse();
});

it('skips computed retained earnings in balance sheet totals when GL retained earnings account exists', function () {
    $company = $this->testCompany;
    $asOf = Carbon::now()->toDateString();

    $referenceEquity = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::Equity)
        ->firstOrFail();

    $retainedGl = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $referenceEquity->subtype_id,
        'code' => '3200.98',
        'name' => 'TEST RETAINED EARNING GL',
    ]);

    $amount = 500_000;
    $bankAccount = $company->default->bankAccount;

    $transaction = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $bankAccount->id,
        ])
        ->asJournal($amount)
        ->create([
            'posted_at' => now()->toDateTimeString(),
        ]);

    $entryDefaults = [
        'company_id' => $company->id,
        'created_by' => $this->testUser->id,
        'updated_by' => $this->testUser->id,
    ];

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->account_id,
        'type' => JournalEntryType::Debit,
        'amount' => $amount,
    ]));

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $retainedGl->id,
        'type' => JournalEntryType::Credit,
        'amount' => $amount,
    ]));

    $report = Reporting::buildBalanceSheetReport($asOf, [], null, $company->id);

    expect($report->categories['Equity']->types ?? [])
        ->not->toHaveKey('Retained Earnings');

    $equitySummary = CurrencyConverter::convertToFloat(
        $report->categories['Equity']->summary->endingBalance ?? '0'
    );

    $startDate = app(ReportService::class)->getEarliestTransactionDate();
    $accounts = app(AccountService::class)->getAccountBalances($startDate, $asOf, [], null, $company->id)->get();
    $excludedIds = Account::balanceSheetExcludedAccountIdsForCompany($company->id)->flip();

    $expectedEquityGl = 0;
    foreach ($accounts as $account) {
        if ($account->type->getCategory() !== AccountCategory::Equity || $excludedIds->has($account->id)) {
            continue;
        }

        $balances = Reporting::calculateAccountBalances($account);
        $expectedEquityGl += ($balances['ending_balance'] ?? 0) / 100;
    }

    $computedRetainedEarnings = Reporting::calculateRetainedEarnings($startDate, $asOf, null, $company->id)->getAmount() / 100;

    expect(abs($equitySummary - $expectedEquityGl))->toBeLessThan(0.02);

    if (abs($computedRetainedEarnings) > 0.01) {
        expect(abs($equitySummary - ($expectedEquityGl + $computedRetainedEarnings)))->toBeGreaterThan(0.02);
    }
});

it('balance sheet category totals satisfy assets equals liabilities plus equity', function () {
    $company = $this->testCompany;
    $asOf = Carbon::now()->toDateString();

    $report = Reporting::buildBalanceSheetReport($asOf, [], null, $company->id);

    $assets = CurrencyConverter::convertToFloat($report->categories['Assets']->summary->endingBalance ?? '0');
    $liabilities = CurrencyConverter::convertToFloat($report->categories['Liabilities']->summary->endingBalance ?? '0');
    $equity = CurrencyConverter::convertToFloat($report->categories['Equity']->summary->endingBalance ?? '0');

    $diff = abs($assets - ($liabilities + $equity));

    expect($diff)->toBeLessThan(0.02);
});

it('shows mapped Laba Tahun Berjalan account on balance sheet instead of generic Retained Earnings', function () {
    $company = $this->testCompany;
    $asOf = Carbon::now()->toDateString();

    $referenceEquity = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::Equity)
        ->firstOrFail();

    $ltjAccount = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $referenceEquity->subtype_id,
        'code' => '3300.02',
        'name' => 'Laba Tahun Berjalan',
    ]);

    AccountDefault::query()->create([
        'company_id' => $company->id,
        'account_id' => $ltjAccount->id,
        'key' => AccountDefaultKey::CurrentYearEarnings,
    ]);

    $revenueAccount = Account::query()
        ->where('company_id', $company->id)
        ->where('category', AccountCategory::Revenue)
        ->firstOrFail();

    $amount = 300_000;
    $bankAccount = $company->default->bankAccount;

    $transaction = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $bankAccount->id,
        ])
        ->asJournal($amount)
        ->create([
            'posted_at' => now()->toDateTimeString(),
        ]);

    $entryDefaults = [
        'company_id' => $company->id,
        'created_by' => $this->testUser->id,
        'updated_by' => $this->testUser->id,
    ];

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->account_id,
        'type' => JournalEntryType::Debit,
        'amount' => $amount,
    ]));

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $revenueAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => $amount,
    ]));

    expect(Account::shouldShowComputedCurrentYearEarningsOnBalanceSheet($company->id))->toBeTrue();

    $report = Reporting::buildBalanceSheetReport($asOf, [], null, $company->id);
    $equityTypes = $report->categories['Equity']->types ?? [];

    expect($equityTypes)
        ->toHaveKey('Laba Tahun Berjalan')
        ->not->toHaveKey('Retained Earnings');

    $ltjLine = $equityTypes['Laba Tahun Berjalan']->accounts[0];

    expect($ltjLine->accountCode)->toBe('3300.02')
        ->and($ltjLine->accountName)->toBe('Laba Tahun Berjalan')
        ->and($ltjLine->accountId)->toBeNull();

    $computedAmount = Reporting::calculateRetainedEarnings(
        app(ReportService::class)->getEarliestTransactionDate(),
        $asOf,
        null,
        $company->id
    )->getAmount() / 100;

    $displayedAmount = CurrencyConverter::convertToFloat($ltjLine->balance->endingBalance ?? '0');

    expect(abs($displayedAmount - $computedAmount))->toBeLessThan(0.02);
});

it('shows computed Laba Tahun Berjalan when mapped even if GL retained earnings account exists', function () {
    $company = $this->testCompany;
    $asOf = Carbon::now()->toDateString();

    $referenceEquity = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::Equity)
        ->firstOrFail();

    $ltjAccount = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $referenceEquity->subtype_id,
        'code' => '3300.03',
        'name' => 'Laba Tahun Berjalan Mapped',
    ]);

    AccountDefault::query()->create([
        'company_id' => $company->id,
        'account_id' => $ltjAccount->id,
        'key' => AccountDefaultKey::CurrentYearEarnings,
    ]);

    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $referenceEquity->subtype_id,
        'code' => '3200.97',
        'name' => 'TEST RETAINED EARNING GL LTJ',
    ]);

    $revenueAccount = Account::query()
        ->where('company_id', $company->id)
        ->where('category', AccountCategory::Revenue)
        ->firstOrFail();

    $amount = 150_000;
    $bankAccount = $company->default->bankAccount;

    $transaction = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $bankAccount->id,
        ])
        ->asJournal($amount)
        ->create([
            'posted_at' => now()->toDateTimeString(),
        ]);

    $entryDefaults = [
        'company_id' => $company->id,
        'created_by' => $this->testUser->id,
        'updated_by' => $this->testUser->id,
    ];

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->account_id,
        'type' => JournalEntryType::Debit,
        'amount' => $amount,
    ]));

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $revenueAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => $amount,
    ]));

    expect(Account::shouldShowComputedCurrentYearEarningsOnBalanceSheet($company->id))->toBeTrue()
        ->and(Account::shouldInjectComputedEquityLineOnBalanceSheet($company->id))->toBeTrue()
        ->and(Account::shouldAddComputedRetainedEarningsToBalanceSheet($company->id))->toBeFalse();

    $report = Reporting::buildBalanceSheetReport($asOf, [], null, $company->id);

    expect($report->categories['Equity']->types ?? [])
        ->toHaveKey('Laba Tahun Berjalan Mapped')
        ->not->toHaveKey('Retained Earnings');
});

it('keeps generic Retained Earnings when Laba Tahun Berjalan default is not configured', function () {
    $company = $this->testCompany;
    $asOf = Carbon::now()->toDateString();

    $revenueAccount = Account::query()
        ->where('company_id', $company->id)
        ->where('category', AccountCategory::Revenue)
        ->firstOrFail();

    $amount = 200_000;
    $bankAccount = $company->default->bankAccount;

    $transaction = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $bankAccount->id,
        ])
        ->asJournal($amount)
        ->create([
            'posted_at' => now()->toDateTimeString(),
        ]);

    $entryDefaults = [
        'company_id' => $company->id,
        'created_by' => $this->testUser->id,
        'updated_by' => $this->testUser->id,
    ];

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->account_id,
        'type' => JournalEntryType::Debit,
        'amount' => $amount,
    ]));

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $revenueAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => $amount,
    ]));

    expect(Account::getCurrentYearEarningsAccount($company->id))->toBeNull();

    $report = Reporting::buildBalanceSheetReport($asOf, [], null, $company->id);

    expect($report->categories['Equity']->types ?? [])
        ->toHaveKey('Retained Earnings')
        ->not->toHaveKey('Laba Tahun Berjalan');
});

it('excludes mapped Laba Tahun Berjalan GL balance from equity totals in favor of computed amount', function () {
    $company = $this->testCompany;
    $asOf = Carbon::now()->toDateString();

    $referenceEquity = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::Equity)
        ->firstOrFail();

    $ltjAccount = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $referenceEquity->subtype_id,
        'code' => '3300.04',
        'name' => 'Laba Tahun Berjalan GL Exclude',
    ]);

    AccountDefault::query()->create([
        'company_id' => $company->id,
        'account_id' => $ltjAccount->id,
        'key' => AccountDefaultKey::CurrentYearEarnings,
    ]);

    $revenueAccount = Account::query()
        ->where('company_id', $company->id)
        ->where('category', AccountCategory::Revenue)
        ->firstOrFail();

    $bankAccount = $company->default->bankAccount;
    $entryDefaults = [
        'company_id' => $company->id,
        'created_by' => $this->testUser->id,
        'updated_by' => $this->testUser->id,
    ];

    $revenueAmount = 400_000;
    $revenueTransaction = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $bankAccount->id,
        ])
        ->asJournal($revenueAmount)
        ->create([
            'posted_at' => now()->toDateTimeString(),
        ]);

    $revenueTransaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->account_id,
        'type' => JournalEntryType::Debit,
        'amount' => $revenueAmount,
    ]));

    $revenueTransaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $revenueAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => $revenueAmount,
    ]));

    $glAmount = 100_000;
    $ltjGlTransaction = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $bankAccount->id,
        ])
        ->asJournal($glAmount)
        ->create([
            'posted_at' => now()->toDateTimeString(),
        ]);

    $ltjGlTransaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->account_id,
        'type' => JournalEntryType::Debit,
        'amount' => $glAmount,
    ]));

    $ltjGlTransaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $ltjAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => $glAmount,
    ]));

    expect(Account::balanceSheetExcludedAccountIdsForCompany($company->id))->toContain($ltjAccount->id);

    $report = Reporting::buildBalanceSheetReport($asOf, [], null, $company->id);
    $ltjLine = $report->categories['Equity']->types['Laba Tahun Berjalan GL Exclude']->accounts[0] ?? null;

    expect($ltjLine)->not->toBeNull();

    $computedAmount = Reporting::calculateRetainedEarnings(
        app(ReportService::class)->getEarliestTransactionDate(),
        $asOf,
        null,
        $company->id
    )->getAmount() / 100;

    $displayedAmount = CurrencyConverter::convertToFloat($ltjLine->balance->endingBalance ?? '0');

    expect(abs($displayedAmount - $computedAmount))->toBeLessThan(0.02)
        ->and(abs($displayedAmount - ($glAmount / 100)))->toBeGreaterThan(0.02);
});
