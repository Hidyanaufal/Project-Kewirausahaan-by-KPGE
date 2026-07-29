<?php

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountType;
use App\Facades\Accounting;
use App\Facades\Reporting;
use App\Factories\ReportDateFactory;
use App\Filament\Company\Pages\Reports\TrialBalance;
use App\Models\Accounting\Account;
use App\Models\Accounting\Transaction;

use function Pest\Livewire\livewire;

it('correctly builds a standard trial balance report', function () {
    $testCompany = $this->testCompany;

    $reportDates = ReportDateFactory::create($testCompany);
    $defaultDateRange = $reportDates->defaultDateRange;
    $defaultStartDate = $reportDates->defaultStartDate->toImmutable();
    $defaultEndDate = $reportDates->defaultEndDate->toImmutable();

    $defaultReportType = 'standard';

    $depositAmount = 1000;
    $withdrawalAmount = 500;
    $depositCount = 10;
    $withdrawalCount = 10;

    Transaction::factory()
        ->forDefaultBankAccount()
        ->forUncategorizedRevenue()
        ->asDeposit($depositAmount)
        ->count($depositCount)
        ->state([
            'posted_at' => $defaultStartDate->subWeek(),
        ])
        ->create();

    Transaction::factory()
        ->forDefaultBankAccount()
        ->forUncategorizedExpense()
        ->asWithdrawal($withdrawalAmount)
        ->count($withdrawalCount)
        ->state([
            'posted_at' => now()->subWeek(),
        ])
        ->create();

    $defaultBankAccountAccount = $testCompany->default->bankAccount->account;
    $earliestTransactionDate = $reportDates->refresh()->earliestTransactionDate;

    $expectedBalances = Accounting::getBalances(
        $defaultBankAccountAccount,
        $earliestTransactionDate->toDateString(),
        $defaultEndDate->toDateString(),
    );

    $calculatedTrialBalances = Reporting::calculateTrialBalances($defaultBankAccountAccount->category, $expectedBalances['ending_balance']);

    $formattedExpectedBalances = Reporting::formatBalances($calculatedTrialBalances);

    livewire(TrialBalance::class)
        ->assertFormSet([
            'deferredFilters.reportType' => $defaultReportType,
            'deferredFilters.dateRange' => $defaultDateRange,
            'deferredFilters.asOfDate' => $defaultEndDate->toDateString(),
        ])
        ->assertSet('filters', [
            'reportType' => $defaultReportType,
            'dateRange' => $defaultDateRange,
            'asOfDate' => $defaultEndDate->toDateString(),
        ])
        ->call('applyFilters')
        ->assertDontSeeText('Retained Earnings')
        ->assertSeeTextInOrder([
            $defaultBankAccountAccount->name,
            $formattedExpectedBalances->debitBalance,
            $formattedExpectedBalances->creditBalance,
        ])
        ->assertReportTableData();
});

it('hides accounts with a zero ending balance from the trial balance', function () {
    $testCompany = $this->testCompany;

    $reportDates = ReportDateFactory::create($testCompany);
    $defaultDateRange = $reportDates->defaultDateRange;
    $defaultStartDate = $reportDates->defaultStartDate->toImmutable();
    $defaultEndDate = $reportDates->defaultEndDate->toImmutable();

    $amount = 1000;

    Transaction::factory()
        ->forDefaultBankAccount()
        ->forUncategorizedRevenue()
        ->asDeposit($amount)
        ->state([
            'posted_at' => $defaultStartDate->subWeek(),
        ])
        ->create();

    Transaction::factory()
        ->forDefaultBankAccount()
        ->forUncategorizedExpense()
        ->asWithdrawal($amount)
        ->state([
            'posted_at' => now()->subWeek(),
        ])
        ->create();

    $defaultBankAccountAccount = $testCompany->default->bankAccount->account;

    livewire(TrialBalance::class)
        ->assertSet('filters', [
            'reportType' => 'standard',
            'dateRange' => $defaultDateRange,
            'asOfDate' => $defaultEndDate->toDateString(),
        ])
        ->call('applyFilters')
        ->assertDontSeeText($defaultBankAccountAccount->name)
        ->assertSeeText('Uncategorized Income')
        ->assertSeeText('Uncategorized Expense');
});

it('correctly builds a post-closing trial balance report', function () {
    $testCompany = $this->testCompany;

    $reportDates = ReportDateFactory::create($testCompany);
    $defaultDateRange = $reportDates->defaultDateRange;
    $defaultStartDate = $reportDates->defaultStartDate->toImmutable();
    $defaultEndDate = $reportDates->defaultEndDate->toImmutable();

    $defaultReportType = 'postClosing';

    $depositAmount = 2000;
    $withdrawalAmount = 1000;
    $depositCount = 5;
    $withdrawalCount = 5;

    Transaction::factory()
        ->forDefaultBankAccount()
        ->forUncategorizedRevenue()
        ->asDeposit($depositAmount)
        ->count($depositCount)
        ->state([
            'posted_at' => $defaultStartDate->subWeek(),
        ])
        ->create();

    Transaction::factory()
        ->forDefaultBankAccount()
        ->forUncategorizedExpense()
        ->asWithdrawal($withdrawalAmount)
        ->count($withdrawalCount)
        ->state([
            'posted_at' => now()->subWeek(),
        ])
        ->create();

    $defaultBankAccountAccount = $testCompany->default->bankAccount->account;
    $earliestTransactionDate = $reportDates->refresh()->earliestTransactionDate;

    $expectedBalances = Accounting::getBalances(
        $defaultBankAccountAccount,
        $earliestTransactionDate->toDateString(),
        $defaultEndDate->toDateString(),
    );

    $calculatedTrialBalances = Reporting::calculateTrialBalances($defaultBankAccountAccount->category, $expectedBalances['ending_balance']);

    $formattedExpectedBalances = Reporting::formatBalances($calculatedTrialBalances);

    $formattedRetainedEarningsBalances = Reporting::getRetainedEarningsBalances($earliestTransactionDate->toDateString(), $defaultEndDate->toDateString());

    // Use Livewire to assert the report's filters and displayed data
    livewire(TrialBalance::class)
        ->set('deferredFilters.reportType', $defaultReportType)
        ->assertFormSet([
            'deferredFilters.reportType' => $defaultReportType,
            'deferredFilters.dateRange' => $defaultDateRange,
            'deferredFilters.asOfDate' => $defaultEndDate->toDateString(),
        ])
        ->call('applyFilters')
        ->assertSet('filters', [
            'reportType' => $defaultReportType,
            'dateRange' => $defaultDateRange,
            'asOfDate' => $defaultEndDate->toDateString(),
        ])
        ->assertSeeTextInOrder([
            $defaultBankAccountAccount->name,
            $formattedExpectedBalances->debitBalance,
            $formattedExpectedBalances->creditBalance,
        ])
        ->assertSeeText('Retained Earnings')
        ->assertSeeTextInOrder([
            'RE',
            'Retained Earnings',
            $formattedRetainedEarningsBalances->debitBalance,
            $formattedRetainedEarningsBalances->creditBalance,
        ])
        ->assertSeeTextInOrder([
            'Total Revenue',
            '$0.00',
            '$0.00',
        ])
        ->assertSeeTextInOrder([
            'Total Expenses',
            '$0.00',
            '$0.00',
        ])
        ->assertReportTableData();
});

it('excludes parent control accounts from trial balance display and totals', function () {
    $company = $this->testCompany;
    $asOf = now()->toDateString();

    $reference = Account::query()
        ->where('company_id', $company->id)
        ->where('type', \App\Enums\Accounting\AccountType::CurrentAsset)
        ->whereDoesntHave('children')
        ->firstOrFail();

    $parent = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1940',
        'name' => 'TB Parent Control AR',
    ]);

    $child = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1940-001',
        'name' => 'TB Child Piutang',
    ]);

    expect($parent->fresh()->isParentAccount())->toBeTrue();

    $amount = 1_500_000;
    $bankAccount = $company->default->bankAccount;

    $transaction = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $bankAccount->id,
        ])
        ->asJournal($amount * 2)
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
        'type' => \App\Enums\Accounting\JournalEntryType::Credit,
        'amount' => $amount * 2,
    ]));

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $parent->id,
        'type' => \App\Enums\Accounting\JournalEntryType::Debit,
        'amount' => $amount,
    ]));

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $child->id,
        'type' => \App\Enums\Accounting\JournalEntryType::Debit,
        'amount' => $amount,
    ]));

    $report = Reporting::buildTrialBalanceReport('standard', $asOf, [], null, $company->id);
    $assetAccounts = collect($report->categories['Assets']->accounts ?? [])
        ->map(fn ($dto) => $dto->accountName);

    expect($assetAccounts)->toContain('TB Child Piutang')
        ->and($assetAccounts)->not->toContain('TB Parent Control AR');
});

it('excludes opening balance equity clearing account from trial balance', function () {
    $company = $this->testCompany;
    $asOf = now()->toDateString();
    $reference = Account::query()
        ->where('company_id', $company->id)
        ->where('type', \App\Enums\Accounting\AccountType::Equity)
        ->firstOrFail();

    $obe = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '3100.tb',
        'name' => 'OPENING BALANCE EQUITY TB Test',
    ]);

    \App\Models\AccountDefault::query()->updateOrCreate(
        [
            'company_id' => $company->id,
            'key' => \App\Enums\Accounting\AccountDefaultKey::OpenBalance->value,
        ],
        ['account_id' => $obe->id]
    );

    $amount = 2_000_000;
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
        'type' => \App\Enums\Accounting\JournalEntryType::Debit,
        'amount' => $amount,
    ]));
    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $obe->id,
        'type' => \App\Enums\Accounting\JournalEntryType::Credit,
        'amount' => $amount,
    ]));

    expect(Account::trialBalanceExcludedAccountIdsForCompany($company->id))->toContain($obe->id);

    $report = Reporting::buildTrialBalanceReport('standard', $asOf, [], null, $company->id);
    $equityNames = collect($report->categories['Equity']->accounts ?? [])
        ->map(fn ($dto) => $dto->accountName);

    expect($equityNames)->not->toContain('OPENING BALANCE EQUITY TB Test');
});
