<?php

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountType;
use App\Facades\Reporting;
use App\Models\Accounting\Account;
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
