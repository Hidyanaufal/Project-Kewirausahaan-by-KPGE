<?php

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryType;
use App\Facades\Reporting;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Models\Accounting\Transaction;
use App\Models\Banking\BankAccount;
use App\Utilities\Accounting\AccountCode;
use App\Utilities\Currency\CurrencyConverter;
use Carbon\Carbon;

function postCashFlowReconciliationJournal(
    int $companyId,
    int $userId,
    Account $bankGlAccount,
    BankAccount $bankAccount,
    Account $revenueAccount,
    Account $ocaAccount,
    Account $oclAccount,
    string $postedAt,
    int $revenueAmount,
    int $ocaAmount,
    int $oclAmount,
): void {
    $bankAmount = $revenueAmount + $oclAmount - $ocaAmount;

    $transaction = Transaction::factory()
        ->state([
            'company_id' => $companyId,
            'bank_account_id' => $bankAccount->id,
        ])
        ->asJournal($bankAmount)
        ->create([
            'posted_at' => $postedAt,
        ]);

    $entryDefaults = [
        'company_id' => $companyId,
        'created_by' => $userId,
        'updated_by' => $userId,
    ];

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $bankGlAccount->id,
        'type' => JournalEntryType::Debit,
        'amount' => $bankAmount,
    ]));

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $revenueAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => $revenueAmount,
    ]));

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $ocaAccount->id,
        'type' => JournalEntryType::Debit,
        'amount' => $ocaAmount,
    ]));

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $oclAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => $oclAmount,
    ]));
}

it('excludes bank accounts from operating activities and reconciles cash flow overview', function () {
    $company = $this->testCompany;
    $postedAt = Carbon::parse('2026-03-15')->toDateTimeString();
    $startDate = '2026-01-01 00:00:00';
    $endDate = '2026-06-17 23:59:59';

    $bankAccount = $company->default->bankAccount;
    $bankGlAccount = $bankAccount->account;

    $revenueAccount = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::OperatingRevenue)
        ->firstOrFail();

    $ocaSubtype = AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('name', 'Other Current Assets')
        ->firstOrFail();

    $oclSubtype = AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('name', 'Other Current Liabilities')
        ->firstOrFail();

    expect($ocaSubtype->inverse_cash_flow)->toBeTrue();

    $ocaAccount = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $ocaSubtype->id,
        'category' => $ocaSubtype->category,
        'type' => $ocaSubtype->type,
        'code' => AccountCode::generate($ocaSubtype),
        'name' => 'CF Test OCA',
    ]);

    $oclAccount = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $oclSubtype->id,
        'category' => $oclSubtype->category,
        'type' => $oclSubtype->type,
        'code' => AccountCode::generate($oclSubtype),
        'name' => 'CF Test OCL',
    ]);

    $revenueAmount = 3_300_000;
    $ocaAmount = 66_000;
    $oclAmount = 121_000;

    postCashFlowReconciliationJournal(
        $company->id,
        $this->testUser->id,
        $bankGlAccount,
        $bankAccount,
        $revenueAccount,
        $ocaAccount,
        $oclAccount,
        $postedAt,
        $revenueAmount,
        $ocaAmount,
        $oclAmount,
    );

    $report = Reporting::buildCashFlowStatementReport($startDate, $endDate, [], null, $company->id);

    $operatingTypes = $report->categories['Operating Activities']->types ?? [];

    expect(array_key_exists('Cash and Cash Equivalents', $operatingTypes))->toBeFalse()
        ->and(array_key_exists('Cash/Bank', $operatingTypes))->toBeFalse();

    $ocaMovement = CurrencyConverter::convertToFloat(
        $operatingTypes['Other Current Assets']->summary->netMovement ?? '0'
    );

    expect($ocaMovement)->toBe(-660.0);

    $starting = CurrencyConverter::convertToFloat($report->overallTotal->startingBalance ?? '0');
    $net = CurrencyConverter::convertToFloat($report->overallTotal->netMovement ?? '0');
    $computedEnding = CurrencyConverter::convertToFloat($report->overallTotal->endingBalance ?? '0');
    $overviewEnding = CurrencyConverter::convertToFloat(
        $report->overview->categories['Ending Balance']->summary->endingBalance ?? '0'
    );

    expect($net)->toBe(33_550.0)
        ->and(abs(($starting + $net) - $overviewEnding))->toBeLessThan(0.02)
        ->and(abs($computedEnding - $overviewEnding))->toBeLessThan(0.02);
});

it('excludes legacy Cash/Bank subtype accounts linked to bank accounts from operating activities', function () {
    $company = $this->testCompany;

    $legacySubtype = AccountSubtype::query()->create([
        'company_id' => $company->id,
        'multi_currency' => true,
        'inverse_cash_flow' => false,
        'category' => AccountType::CurrentAsset->getCategory(),
        'type' => AccountType::CurrentAsset,
        'name' => 'Cash/Bank',
        'description' => 'Legacy cash subtype',
    ]);

    $legacyBankGl = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $legacySubtype->id,
        'category' => $legacySubtype->category,
        'type' => $legacySubtype->type,
        'code' => AccountCode::generate($legacySubtype),
        'name' => 'Legacy Cash/Bank Account',
    ]);

    BankAccount::factory()->create([
        'company_id' => $company->id,
        'account_id' => $legacyBankGl->id,
    ]);

    $postedAt = Carbon::parse('2026-04-01')->toDateTimeString();
    $startDate = '2026-01-01 00:00:00';
    $endDate = '2026-06-17 23:59:59';

    $revenueAccount = Account::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::OperatingRevenue)
        ->firstOrFail();

    $transaction = Transaction::factory()
        ->state([
            'company_id' => $company->id,
            'bank_account_id' => $company->default->bankAccount->id,
        ])
        ->asJournal(500_000)
        ->create([
            'posted_at' => $postedAt,
        ]);

    $entryDefaults = [
        'company_id' => $company->id,
        'created_by' => $this->testUser->id,
        'updated_by' => $this->testUser->id,
    ];

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $legacyBankGl->id,
        'type' => JournalEntryType::Debit,
        'amount' => 500_000,
    ]));

    $transaction->journalEntries()->create(array_merge($entryDefaults, [
        'account_id' => $revenueAccount->id,
        'type' => JournalEntryType::Credit,
        'amount' => 500_000,
    ]));

    $report = Reporting::buildCashFlowStatementReport($startDate, $endDate, [], null, $company->id);
    $operatingTypes = $report->categories['Operating Activities']->types ?? [];

    expect(array_key_exists('Cash/Bank', $operatingTypes))->toBeFalse();
});
