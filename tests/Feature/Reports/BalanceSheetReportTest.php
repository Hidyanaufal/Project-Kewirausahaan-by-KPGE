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
