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
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

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

it('detects code-prefix parent accounts set-based without parent_id', function () {
    $company = $this->testCompany;

    $referenceEquity = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::Equity)
        ->firstOrFail();

    $parent = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $referenceEquity->subtype_id,
        'code' => '8888',
        'name' => 'BS Code Prefix Parent',
        'parent_id' => null,
    ]);

    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $referenceEquity->subtype_id,
        'code' => '8888.10',
        'name' => 'BS Code Prefix Child',
        'parent_id' => null,
    ]);

    $parentIds = Account::parentAccountIdsForCompany($company->id);

    expect($parentIds)->toContain($parent->id)
        ->and($parent->fresh()->isParentAccount())->toBeTrue();
});

it('builds balance sheet retained earnings without extra balance aggregation queries', function () {
    $company = $this->testCompany;
    $asOf = Carbon::now()->toDateString();

    DB::flushQueryLog();
    DB::enableQueryLog();

    Reporting::buildBalanceSheetReport($asOf, [], null, $company->id);

    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    $balanceAggregations = $queries->filter(function (array $query) {
        $sql = strtolower($query['query']);

        return str_contains($sql, 'journal_entries')
            && str_contains($sql, 'starting_balance');
    });

    expect($balanceAggregations)->toHaveCount(1);
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
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '9996',
        'name' => 'Test Open Balance Equity',
    ]);

    AccountDefault::query()->updateOrCreate(
        [
            'company_id' => $company->id,
            'key' => AccountDefaultKey::OpenBalance->value,
        ],
        ['account_id' => $clearingAccount->id],
    );

    expect(Account::balanceSheetExcludedAccountIdsForCompany($company->id))
        ->toContain($clearingAccount->id)
        ->and(Account::balanceSheetClearingAccountIdsForCompany($company->id))
        ->toContain($clearingAccount->id);

    $amount = 3_500_000;
    $bankAccount = $company->default->bankAccount;
    $transaction = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $bankAccount->id,
        ])
        ->asJournal($amount)
        ->create([
            'posted_at' => now()->subDay()->toDateTimeString(),
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
        'account_id' => $clearingAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => $amount,
    ]));

    $report = Reporting::buildBalanceSheetReport($asOf, [], null, $company->id);

    $equityNames = collect($report->categories['Equity']->types ?? [])
        ->flatMap(fn ($typeDto) => $typeDto->accounts ?? [])
        ->map(fn ($dto) => $dto->accountName);

    expect($equityNames)->not->toContain('Test Open Balance Equity');

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

it('hides legacy RETAINED EARNING on balance sheet when Laba Ditahan exists', function () {
    $company = $this->testCompany;
    $asOf = Carbon::now()->toDateString();

    $referenceEquity = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::Equity)
        ->firstOrFail();

    $labaDitahan = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $referenceEquity->subtype_id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '3002.bs',
        'name' => 'Laba Ditahan',
    ]);

    $legacyRe = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $referenceEquity->subtype_id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '320001.bs',
        'name' => 'RETAINED EARNING',
    ]);

    expect(Account::duplicateRetainedEarningsAccountIdsForCompany($company->id))
        ->toContain($legacyRe->id)
        ->not->toContain($labaDitahan->id)
        ->and(Account::balanceSheetHiddenLineAccountIdsForCompany($company->id))
        ->toContain($legacyRe->id)
        ->and(Account::balanceSheetExcludedAccountIdsForCompany($company->id))
        ->toContain($legacyRe->id);

    $amount = 4_200_000;
    $labaAmount = 1_500_000;
    $bankAccount = $company->default->bankAccount;
    $transaction = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $bankAccount->id,
        ])
        ->asJournal($amount + $labaAmount)
        ->create([
            'posted_at' => now()->subDay()->toDateTimeString(),
        ]);

    $entryDefaults = [
        'company_id' => $company->id,
        'created_by' => $this->testUser->id,
        'updated_by' => $this->testUser->id,
    ];

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->account_id,
        'type' => JournalEntryType::Debit,
        'amount' => $amount + $labaAmount,
    ]));
    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $legacyRe->id,
        'type' => JournalEntryType::Credit,
        'amount' => $amount,
    ]));
    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $labaDitahan->id,
        'type' => JournalEntryType::Credit,
        'amount' => $labaAmount,
    ]));

    $report = Reporting::buildBalanceSheetReport($asOf, [], null, $company->id);

    $equityNames = collect($report->categories['Equity']->types ?? [])
        ->flatMap(fn ($typeDto) => $typeDto->accounts ?? [])
        ->map(fn ($dto) => $dto->accountName);

    expect($equityNames)->not->toContain('RETAINED EARNING')
        ->and($equityNames)->toContain('Laba Ditahan');
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

it('injects Laba Tahun Berjalan when GL retained earnings exists alongside nominal accounts', function () {
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

    expect(Account::shouldAddComputedRetainedEarningsToBalanceSheet($company->id))->toBeFalse()
        ->and(Account::shouldInjectComputedEquityLineOnBalanceSheet($company->id))->toBeTrue()
        ->and(Account::companyHasNominalAccounts($company->id))->toBeTrue();

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

    $computedCurrentYearEarnings = Reporting::calculateRetainedEarnings($startDate, $asOf, null, $company->id)->getAmount() / 100;

    expect(abs($equitySummary - ($expectedEquityGl + $computedCurrentYearEarnings)))->toBeLessThan(0.02);

    if (abs($computedCurrentYearEarnings) > 0.01) {
        expect($report->categories['Equity']->types ?? [])->toHaveKey('Laba Tahun Berjalan');
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

it('computes total assets as current plus non-current minus contra assets', function () {
    $company = $this->testCompany;
    $asOf = Carbon::now()->toDateString();

    $assetsBefore = CurrencyConverter::convertToFloat(
        Reporting::buildBalanceSheetReport($asOf, [], null, $company->id)
            ->categories['Assets']->summary->endingBalance ?? '0'
    );

    $contraSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::ContraAsset)
        ->firstOrFail();

    $contraAsset = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $contraSubtype->id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::ContraAsset,
        'code' => '1499.bs',
        'name' => 'BS Test Accumulated Depreciation',
    ]);

    $expenseAccount = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::OperatingExpense)
        ->firstOrFail();

    $amount = 175_000;
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
        'account_id' => $expenseAccount->id,
        'type' => JournalEntryType::Debit,
        'amount' => $amount,
    ]));

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $contraAsset->id,
        'type' => JournalEntryType::Credit,
        'amount' => $amount,
    ]));

    $report = Reporting::buildBalanceSheetReport($asOf, [], null, $company->id);
    $assets = $report->categories['Assets'];

    $typeBalance = function (string $typeName) use ($assets): float {
        if (! isset($assets->types[$typeName])) {
            return 0.0;
        }

        return CurrencyConverter::convertToFloat($assets->types[$typeName]->summary->endingBalance ?? '0');
    };

    $current = $typeBalance('Current Assets');
    $nonCurrent = $typeBalance('Non-Current Assets');
    $contra = $typeBalance('Contra Assets');
    $totalAssets = CurrencyConverter::convertToFloat($assets->summary->endingBalance ?? '0');

    expect(abs($totalAssets - ($current - $contra + $nonCurrent)))->toBeLessThan(0.02)
        ->and($contra)->toBeGreaterThanOrEqual(($amount / 100) - 0.02)
        ->and(abs($totalAssets - ($assetsBefore - ($amount / 100))))->toBeLessThan(0.02);
});

it('keeps balance sheet details export leaf endings in parity with summary for cutover OB at 23:59:59', function () {
    $company = $this->testCompany;
    $cutoverDay = Carbon::parse('2025-12-31');
    $obPostedAt = $cutoverDay->copy()->endOfDay()->toDateTimeString();
    $asOf = $cutoverDay->toDateString();
    $amount = 4_200_000;

    $bankAccount = $company->default->bankAccount;
    $assetAccount = $bankAccount->account;
    $equity = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::Equity)
        ->whereDoesntHave('children')
        ->whereNotIn('id', Account::balanceSheetHiddenLineAccountIdsForCompany($company->id))
        ->firstOrFail();

    $transaction = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $bankAccount->id,
        ])
        ->asJournal($amount)
        ->create([
            'posted_at' => $obPostedAt,
        ]);

    $entryDefaults = [
        'company_id' => $company->id,
        'created_by' => $this->testUser->id,
        'updated_by' => $this->testUser->id,
    ];

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $assetAccount->id,
        'type' => JournalEntryType::Debit,
        'amount' => $amount,
    ]));
    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $equity->id,
        'type' => JournalEntryType::Credit,
        'amount' => $amount,
    ]));

    // Same as-of datetime the Filament page uses (endOfDay).
    $asOfDateTime = $cutoverDay->copy()->endOfDay()->toDateTimeString();
    $summary = Reporting::buildBalanceSheetReport($asOfDateTime, [], null, $company->id);
    $summaryByCode = collect($summary->categories)
        ->flatMap(fn ($cat) => collect($cat->types ?? [])->flatMap(fn ($type) => $type->accounts ?? []))
        ->filter(fn ($dto) => $dto->accountId !== null)
        ->keyBy(fn ($dto) => $dto->accountCode);

    $component = livewire(\App\Filament\Company\Pages\Reports\BalanceSheet::class)
        ->set('filters.asOfDate', $asOf)
        ->set('deferredFilters.asOfDate', $asOf)
        ->call('applyFilters');

    $details = $component->instance()->buildReportEnhanced();
    $excluded = Account::balanceSheetExcludedAccountIdsForCompany($company->id)->flip();
    $hidden = Account::balanceSheetHiddenLineAccountIdsForCompany($company->id)->flip();

    $leafRows = collect($details['rows'])->filter(function ($row) {
        $label = $row['data'][0] ?? '';
        $balance = $row['data'][1] ?? null;

        if ($balance === null || $balance === '') {
            return false;
        }
        // Skip category/type headers and Total* rows
        if (! isset($row['data'][1]) || is_string($row['data'][1]) === false && ! is_numeric($row['data'][1])) {
            return false;
        }
        if (str_starts_with((string) $label, 'Total ')) {
            return false;
        }
        // Pure uppercase category headers have no numeric balance in col 1 as second element only sometimes
        if (count($row['data']) < 2) {
            return false;
        }

        return is_numeric($row['data'][1]);
    });

    $compared = 0;
    foreach ($leafRows as $row) {
        $label = (string) $row['data'][0];
        // Details labels are "{code} {name}"
        $code = explode(' ', $label, 2)[0] ?? '';
        if ($code === '' || ! $summaryByCode->has($code)) {
            continue;
        }

        $accountId = $summaryByCode[$code]->accountId;
        if ($excluded->has($accountId) || $hidden->has($accountId)) {
            continue;
        }

        $summaryEnding = CurrencyConverter::convertToFloat(
            $summaryByCode[$code]->balance->endingBalance ?? '0'
        );
        $detailsEnding = (float) $row['data'][1];

        expect(abs($detailsEnding - $summaryEnding))->toBeLessThan(0.02);
        $compared++;
    }

    expect($compared)->toBeGreaterThan(0)
        ->and($summaryByCode->has($assetAccount->code))->toBeTrue();

    $assetSummary = CurrencyConverter::convertToFloat(
        $summaryByCode[$assetAccount->code]->balance->endingBalance ?? '0'
    );
    expect($assetSummary)->toBeGreaterThanOrEqual(($amount / 100) - 0.02);
});

it('places contra-asset children under Contra Assets in details export, not under current-asset parent', function () {
    $company = $this->testCompany;
    $asOf = Carbon::now()->toDateString();
    $receivableAmount = 500_000; // cents
    $allowanceAmount = 75_000; // cents

    $currentSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::CurrentAsset)
        ->firstOrFail();

    $contraSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::ContraAsset)
        ->firstOrFail();

    $piutangParent = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $currentSubtype->id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::CurrentAsset,
        'code' => '1190.bs',
        'name' => 'BS Test Piutang Parent',
        'parent_id' => null,
    ]);

    $receivable = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $currentSubtype->id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::CurrentAsset,
        'code' => '1190.01.bs',
        'name' => 'BS Test Piutang Child',
        'parent_id' => $piutangParent->id,
    ]);

    $allowance = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $contraSubtype->id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::ContraAsset,
        'code' => '1190.10.bs',
        'name' => 'BS Test Penyisihan Provisi Kerugian Piutang',
        'parent_id' => $piutangParent->id,
    ]);

    $equity = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::Equity)
        ->whereDoesntHave('children')
        ->whereNotIn('id', Account::balanceSheetHiddenLineAccountIdsForCompany($company->id))
        ->firstOrFail();

    $bankAccount = $company->default->bankAccount;
    $entryDefaults = [
        'company_id' => $company->id,
        'created_by' => $this->testUser->id,
        'updated_by' => $this->testUser->id,
    ];

    $txReceivable = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $bankAccount->id,
        ])
        ->asJournal($receivableAmount)
        ->create(['posted_at' => now()->toDateTimeString()]);

    $txReceivable->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $receivable->id,
        'type' => JournalEntryType::Debit,
        'amount' => $receivableAmount,
    ]));
    $txReceivable->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $equity->id,
        'type' => JournalEntryType::Credit,
        'amount' => $receivableAmount,
    ]));

    $txAllowance = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $bankAccount->id,
        ])
        ->asJournal($allowanceAmount)
        ->create(['posted_at' => now()->toDateTimeString()]);

    $expense = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::OperatingExpense)
        ->firstOrFail();

    $txAllowance->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $expense->id,
        'type' => JournalEntryType::Debit,
        'amount' => $allowanceAmount,
    ]));
    $txAllowance->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $allowance->id,
        'type' => JournalEntryType::Credit,
        'amount' => $allowanceAmount,
    ]));

    $summary = Reporting::buildBalanceSheetReport(
        Carbon::parse($asOf)->endOfDay()->toDateTimeString(),
        [],
        null,
        $company->id
    );
    $summaryAssets = CurrencyConverter::convertToFloat(
        $summary->categories['Assets']->summary->endingBalance ?? '0'
    );

    $component = livewire(\App\Filament\Company\Pages\Reports\BalanceSheet::class)
        ->set('filters.asOfDate', $asOf)
        ->set('deferredFilters.asOfDate', $asOf)
        ->call('applyFilters');

    $details = $component->instance()->buildReportEnhanced();
    $rows = collect($details['rows']);

    $labels = $rows->map(fn ($row) => (string) ($row['data'][0] ?? ''))->values();

    $allowanceLabel = $allowance->code . ' ' . $allowance->name;
    $allowanceIndex = $labels->search($allowanceLabel);
    expect($allowanceIndex)->not->toBeFalse();

    // Allowance must sit under a Contra Asset type header, not under Current Asset.
    $typeHeaderBefore = $labels
        ->take($allowanceIndex)
        ->reverse()
        ->first(fn ($label) => in_array($label, ['Current asset', 'Non current asset', 'Contra asset'], true));

    expect($typeHeaderBefore)->toBe('Contra asset');

    $totalCurrent = $rows->first(
        fn ($row) => ($row['data'][0] ?? '') === 'Total Current asset'
    );
    $totalContra = $rows->first(
        fn ($row) => ($row['data'][0] ?? '') === 'Total Contra asset'
    );
    $totalAssets = $rows->first(
        fn ($row) => ($row['data'][0] ?? '') === 'Total ASSET'
    );

    expect($totalCurrent)->not->toBeNull()
        ->and($totalContra)->not->toBeNull()
        ->and($totalAssets)->not->toBeNull();

    $currentTotal = (float) $totalCurrent['data'][1];
    $contraTotal = (float) $totalContra['data'][1];
    $assetsTotal = (float) $totalAssets['data'][1];

    // Parent group must not inflate Current Assets with the allowance balance.
    expect($currentTotal)->toBeLessThan(($receivableAmount / 100) + ($allowanceAmount / 100) - 0.02)
        ->and($contraTotal)->toBeGreaterThanOrEqual(($allowanceAmount / 100) - 0.02)
        ->and(abs($assetsTotal - $summaryAssets))->toBeLessThan(0.02);
});

it('appends total liabilities plus equity at the bottom of details export', function () {
    $company = $this->testCompany;
    $asOf = Carbon::now()->toDateString();

    $component = livewire(\App\Filament\Company\Pages\Reports\BalanceSheet::class)
        ->set('filters.asOfDate', $asOf)
        ->set('deferredFilters.asOfDate', $asOf)
        ->call('applyFilters');

    $details = $component->instance()->buildReportEnhanced();
    $rows = collect($details['rows']);

    $totalLiability = $rows->first(fn ($row) => ($row['data'][0] ?? '') === 'Total LIABILITY');
    $totalEquity = $rows->first(fn ($row) => ($row['data'][0] ?? '') === 'Total EQUITY');
    $combined = $rows->last();

    expect($combined['data'][0] ?? null)->toBe('Total Liabilities + Equity');

    $expected = (float) ($totalLiability['data'][1] ?? 0) + (float) ($totalEquity['data'][1] ?? 0);
    expect(abs((float) $combined['data'][1] - $expected))->toBeLessThan(0.02);

    $report = Reporting::buildBalanceSheetReport($asOf, [], null, $company->id);
    $transformer = new BalanceSheetReportTransformer($report);
    $overall = $transformer->getOverallTotals();

    expect($overall['account_name'] ?? null)->toBe('Total Liabilities + Equity')
        ->and(abs(
            CurrencyConverter::convertToFloat($overall['ending_balance'] ?? '0')
            - (
                CurrencyConverter::convertToFloat($report->categories['Liabilities']->summary->endingBalance ?? '0')
                + CurrencyConverter::convertToFloat($report->categories['Equity']->summary->endingBalance ?? '0')
            )
        ))->toBeLessThan(0.02);
});
