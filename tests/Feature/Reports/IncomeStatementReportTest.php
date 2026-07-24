<?php

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryType;
use App\Facades\Reporting;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Models\Accounting\Transaction;
use App\Utilities\Currency\CurrencyConverter;
use Carbon\Carbon;

it('calculates revenue net movement as credit minus debit', function () {
    $account = Account::factory()->make([
        'category' => AccountCategory::Revenue,
        'type' => AccountType::OperatingRevenue,
    ]);
    $account->total_debit = 25_000;
    $account->total_credit = 100_000;
    $account->starting_balance = 0;

    $balances = Reporting::calculateAccountBalances($account);

    expect($balances['net_movement'])->toBe(75_000);
});

it('calculates expense and cogs net movement as debit minus credit', function () {
    $expense = Account::factory()->make([
        'category' => AccountCategory::Expense,
        'type' => AccountType::OperatingExpense,
    ]);
    $expense->total_debit = 80_000;
    $expense->total_credit = 10_000;
    $expense->starting_balance = 0;

    $balances = Reporting::calculateAccountBalances($expense);

    expect($balances['net_movement'])->toBe(70_000);
});

it('calculates contra revenue net movement as debit minus credit', function () {
    $account = Account::factory()->make([
        'category' => AccountCategory::Revenue,
        'type' => AccountType::ContraRevenue,
    ]);
    $account->total_debit = 100_000;
    $account->total_credit = 0;
    $account->starting_balance = 0;

    $balances = Reporting::calculateAccountBalances($account);

    expect($balances['net_movement'])->toBe(100_000)
        ->and($account->type->isContra())->toBeTrue()
        ->and($account->type->isNormalDebitBalance())->toBeTrue();
});

it('subtracts contra revenue from total revenue on the income statement', function () {
    $company = $this->testCompany;
    $start = Carbon::now()->startOfMonth()->toDateTimeString();
    $end = Carbon::now()->endOfMonth()->toDateTimeString();
    $postedAt = Carbon::now()->toDateTimeString();

    $revenueBefore = parseIncomeStatementCategoryAmount(
        Reporting::buildIncomeStatementReport($start, $end, [], null, $company->id),
        'Revenue'
    );

    $revenueSubtype = AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::OperatingRevenue)
        ->firstOrFail();

    $contraRevenueSubtype = AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::ContraRevenue)
        ->firstOrFail();

    $revenueAccount = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $revenueSubtype->id,
        'code' => '4100.is',
        'name' => 'IS Test Sales Revenue',
    ]);

    $contraRevenue = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $contraRevenueSubtype->id,
        'code' => '4900.is',
        'name' => 'IS Test Sales Returns',
    ]);

    $bankAccount = $company->default->bankAccount->account;
    $entryDefaults = journalEntryDefaults($this->testUser->id, $company->id);

    // Sales: debit bank 1_000_000, credit revenue 1_000_000
    $salesTxn = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $company->default->bankAccount->id,
        ])
        ->asJournal(1_000_000)
        ->create(['posted_at' => $postedAt]);

    $salesTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->id,
        'type' => JournalEntryType::Debit,
        'amount' => 1_000_000,
    ]));
    $salesTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $revenueAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => 1_000_000,
    ]));

    // Sales return: debit contra revenue 100_000, credit bank 100_000
    $returnTxn = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $company->default->bankAccount->id,
        ])
        ->asJournal(100_000)
        ->create(['posted_at' => $postedAt]);

    $returnTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $contraRevenue->id,
        'type' => JournalEntryType::Debit,
        'amount' => 100_000,
    ]));
    $returnTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => 100_000,
    ]));

    $report = Reporting::buildIncomeStatementReport($start, $end, [], null, $company->id);
    $revenueTotal = parseIncomeStatementCategoryAmount($report, 'Revenue');

    // Net revenue contribution from these accounts: 1_000_000 - 100_000 = 900_000 cents
    expect(abs($revenueTotal - ($revenueBefore + 9_000.0)))->toBeLessThan(0.02);

    $contraLine = collect($report->categories['Revenue']->accounts)
        ->first(fn ($a) => $a->accountName === 'IS Test Sales Returns');

    expect($contraLine)->not->toBeNull();
    expect(CurrencyConverter::convertToFloat($contraLine->balance->netMovement ?? '0'))
        ->toBeLessThan(0); // shown as negative after contra negation
});

it('subtracts contra expense from total expenses on the income statement', function () {
    $company = $this->testCompany;
    $start = Carbon::now()->startOfMonth()->toDateTimeString();
    $end = Carbon::now()->endOfMonth()->toDateTimeString();
    $postedAt = Carbon::now()->toDateTimeString();

    $expensesBefore = parseIncomeStatementCategoryAmount(
        Reporting::buildIncomeStatementReport($start, $end, [], null, $company->id),
        'Expenses'
    );

    $expenseSubtype = AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::OperatingExpense)
        ->where('name', '!=', 'Cost of Goods Sold')
        ->firstOrFail();

    $contraExpenseSubtype = AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::ContraExpense)
        ->firstOrFail();

    $expenseAccount = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $expenseSubtype->id,
        'code' => '5100.is',
        'name' => 'IS Test Operating Expense',
    ]);

    $contraExpense = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $contraExpenseSubtype->id,
        'code' => '5900.is',
        'name' => 'IS Test Purchase Discount',
    ]);

    $bankAccount = $company->default->bankAccount->account;
    $entryDefaults = journalEntryDefaults($this->testUser->id, $company->id);

    // Expense: debit expense 150_000, credit bank 150_000
    $expenseTxn = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $company->default->bankAccount->id,
        ])
        ->asJournal(150_000)
        ->create(['posted_at' => $postedAt]);

    $expenseTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $expenseAccount->id,
        'type' => JournalEntryType::Debit,
        'amount' => 150_000,
    ]));
    $expenseTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => 150_000,
    ]));

    // Contra expense: debit bank 50_000, credit contra expense 50_000
    $discountTxn = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $company->default->bankAccount->id,
        ])
        ->asJournal(50_000)
        ->create(['posted_at' => $postedAt]);

    $discountTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->id,
        'type' => JournalEntryType::Debit,
        'amount' => 50_000,
    ]));
    $discountTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $contraExpense->id,
        'type' => JournalEntryType::Credit,
        'amount' => 50_000,
    ]));

    $report = Reporting::buildIncomeStatementReport($start, $end, [], null, $company->id);
    $expensesTotal = parseIncomeStatementCategoryAmount($report, 'Expenses');

    // Net expense contribution: 150_000 - 50_000 = 100_000 cents
    expect(abs($expensesTotal - ($expensesBefore + 1_000.0)))->toBeLessThan(0.02);
});

it('does not include contra asset accounts on the income statement', function () {
    $company = $this->testCompany;
    $start = Carbon::now()->startOfMonth()->toDateTimeString();
    $end = Carbon::now()->endOfMonth()->toDateTimeString();
    $postedAt = Carbon::now()->toDateTimeString();

    $contraSubtype = AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::ContraAsset)
        ->firstOrFail();

    $contraAsset = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $contraSubtype->id,
        'code' => '1499.is',
        'name' => 'IS Test Accumulated Depreciation',
    ]);

    $expenseAccount = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::OperatingExpense)
        ->whereRelation('subtype', 'name', '!=', 'Cost of Goods Sold')
        ->firstOrFail();

    $amount = 175_000;
    $entryDefaults = journalEntryDefaults($this->testUser->id, $company->id);

    $transaction = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $company->default->bankAccount->id,
        ])
        ->asJournal($amount)
        ->create(['posted_at' => $postedAt]);

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

    $report = Reporting::buildIncomeStatementReport($start, $end, [], null, $company->id);

    $allAccountNames = collect($report->categories)
        ->flatMap(fn ($category) => collect($category->accounts)->pluck('accountName'))
        ->all();

    expect($allAccountNames)->not->toContain('IS Test Accumulated Depreciation')
        ->and($contraAsset->fresh()->category)->toBe(AccountCategory::Asset);
});

it('computes net earnings as revenue minus cogs minus expenses', function () {
    $company = $this->testCompany;
    $start = Carbon::now()->startOfMonth()->toDateTimeString();
    $end = Carbon::now()->endOfMonth()->toDateTimeString();
    $postedAt = Carbon::now()->toDateTimeString();

    $before = Reporting::buildIncomeStatementReport($start, $end, [], null, $company->id);
    $netBefore = CurrencyConverter::convertToFloat($before->overallTotal->netMovement ?? '0');

    $revenueSubtype = AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::OperatingRevenue)
        ->firstOrFail();

    $cogsSubtype = AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('name', 'Cost of Goods Sold')
        ->firstOrFail();

    $expenseSubtype = AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::OperatingExpense)
        ->where('name', '!=', 'Cost of Goods Sold')
        ->firstOrFail();

    $revenueAccount = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $revenueSubtype->id,
        'code' => '4110.is',
        'name' => 'IS Test Net Earnings Revenue',
    ]);

    $cogsAccount = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $cogsSubtype->id,
        'code' => '5010.is',
        'name' => 'IS Test COGS',
    ]);

    $expenseAccount = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $expenseSubtype->id,
        'code' => '5110.is',
        'name' => 'IS Test Net Earnings Expense',
    ]);

    $bankAccount = $company->default->bankAccount->account;
    $entryDefaults = journalEntryDefaults($this->testUser->id, $company->id);

    // Revenue 500_000 credit
    $revTxn = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $company->default->bankAccount->id,
        ])
        ->asJournal(500_000)
        ->create(['posted_at' => $postedAt]);
    $revTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->id,
        'type' => JournalEntryType::Debit,
        'amount' => 500_000,
    ]));
    $revTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $revenueAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => 500_000,
    ]));

    // COGS 120_000 debit
    $cogsTxn = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $company->default->bankAccount->id,
        ])
        ->asJournal(120_000)
        ->create(['posted_at' => $postedAt]);
    $cogsTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $cogsAccount->id,
        'type' => JournalEntryType::Debit,
        'amount' => 120_000,
    ]));
    $cogsTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => 120_000,
    ]));

    // Expense 80_000 debit
    $expTxn = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $company->default->bankAccount->id,
        ])
        ->asJournal(80_000)
        ->create(['posted_at' => $postedAt]);
    $expTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $expenseAccount->id,
        'type' => JournalEntryType::Debit,
        'amount' => 80_000,
    ]));
    $expTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => 80_000,
    ]));

    $report = Reporting::buildIncomeStatementReport($start, $end, [], null, $company->id);

    $revenue = parseIncomeStatementCategoryAmount($report, 'Revenue');
    $cogs = parseIncomeStatementCategoryAmount($report, 'Cost of Goods Sold');
    $expenses = parseIncomeStatementCategoryAmount($report, 'Expenses');
    $netEarnings = CurrencyConverter::convertToFloat($report->overallTotal->netMovement ?? '0');

    expect(abs($netEarnings - ($revenue - $cogs - $expenses)))->toBeLessThan(0.02)
        // Contribution: 5000 - 1200 - 800 = 3000 major units
        ->and(abs($netEarnings - ($netBefore + 3_000.0)))->toBeLessThan(0.02);
});

it('negates contra accounts when calculating retained earnings', function () {
    $company = $this->testCompany;
    $start = Carbon::parse($this->app->make(\App\Services\AccountService::class)->getEarliestTransactionDate())->toDateTimeString();
    $end = Carbon::now()->endOfMonth()->toDateTimeString();
    $postedAt = Carbon::now()->toDateTimeString();

    $reBefore = Reporting::calculateRetainedEarnings($start, $end, null, $company->id)->getAmount();

    $revenueSubtype = AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::OperatingRevenue)
        ->firstOrFail();

    $contraRevenueSubtype = AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::ContraRevenue)
        ->firstOrFail();

    $revenueAccount = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $revenueSubtype->id,
        'code' => '4120.is',
        'name' => 'IS Test RE Revenue',
    ]);

    $contraRevenue = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $contraRevenueSubtype->id,
        'code' => '4910.is',
        'name' => 'IS Test RE Sales Returns',
    ]);

    $bankAccount = $company->default->bankAccount->account;
    $entryDefaults = journalEntryDefaults($this->testUser->id, $company->id);

    $salesTxn = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $company->default->bankAccount->id,
        ])
        ->asJournal(200_000)
        ->create(['posted_at' => $postedAt]);
    $salesTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->id,
        'type' => JournalEntryType::Debit,
        'amount' => 200_000,
    ]));
    $salesTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $revenueAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => 200_000,
    ]));

    $returnTxn = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $company->default->bankAccount->id,
        ])
        ->asJournal(40_000)
        ->create(['posted_at' => $postedAt]);
    $returnTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $contraRevenue->id,
        'type' => JournalEntryType::Debit,
        'amount' => 40_000,
    ]));
    $returnTxn->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => 40_000,
    ]));

    $reAfter = Reporting::calculateRetainedEarnings($start, $end, null, $company->id)->getAmount();

    // Net contribution: 200_000 - 40_000 = 160_000 cents (not 240_000 if contra were added)
    expect($reAfter - $reBefore)->toBe(160_000);
});

/**
 * @return array{company_id: int, created_by: int, updated_by: int}
 */
function journalEntryDefaults(int $userId, int $companyId): array
{
    return [
        'company_id' => $companyId,
        'created_by' => $userId,
        'updated_by' => $userId,
    ];
}

function parseIncomeStatementCategoryAmount(\App\DTO\ReportDTO $report, string $categoryName): float
{
    if (! isset($report->categories[$categoryName])) {
        return 0.0;
    }

    return CurrencyConverter::convertToFloat(
        $report->categories[$categoryName]->summary->netMovement ?? '0'
    );
}
