<?php

use App\Actions\Addons\GenerateYearlyForAsset;
use App\Enums\Accounting\JournalEntryType;
use App\Models\Accounting\Account;
use App\Models\Accounting\Transaction;
use App\Models\Addons\Asset;
use App\Models\Addons\AssetGroup;
use App\Models\Company;

it('creates balanced monthly depreciation journals (debit asset, credit contra) and is idempotent on force', function () {
    $company = Company::firstOrFail();

    $assetAccount = Account::factory()->create([
        'company_id' => $company->id,
        'name' => 'Asset Account - Test',
    ]);

    $contraAccount = Account::factory()->create([
        'company_id' => $company->id,
        'name' => 'Contra Asset Account - Test',
    ]);

    $group = AssetGroup::create([
        'company_id' => $company->id,
        'name' => 'Test Group',
        'useful_life_months' => 12,
        'default_method' => 'garis_lurus',
        'asset_account_id' => $assetAccount->id,
        'contra_account_id' => $contraAccount->id,
    ]);

    /** @var Asset $asset */
    $asset = Asset::create([
        'company_id' => $company->id,
        'group_id' => $group->id,
        'asset_code' => 'AST-TEST-001',
        'name' => 'Test Asset',
        'acquisition_date' => '2026-01-15',
        'acquisition_cost' => 1200.00,
        'salvage_value' => 0.00,
        'useful_life_months' => 12,
        'depreciation_method' => 'garis_lurus',
        'status' => 'active',
    ]);

    GenerateYearlyForAsset::run(asset: $asset, targetYear: 2026, force: true);

    $transactions = Transaction::query()
        ->where('type', 'journal')
        ->where('company_id', $company->id)
        ->where('meta->addon', 'asset_depreciation')
        ->where('meta->asset_id', $asset->id)
        ->where('meta->fiscal_year', 2026)
        ->get();

    expect($transactions)->toHaveCount(12);

    foreach ($transactions as $transaction) {
        $entries = $transaction->journalEntries()->get();

        expect($entries)->toHaveCount(2)
            ->and($entries->sumDebits()->getAmount())->toBe($entries->sumCredits()->getAmount());

        $debit = $entries->firstWhere('type', JournalEntryType::Debit);
        $credit = $entries->firstWhere('type', JournalEntryType::Credit);

        expect($debit)->not->toBeNull()
            ->and($credit)->not->toBeNull()
            ->and($debit->account_id)->toBe($assetAccount->id)
            ->and($credit->account_id)->toBe($contraAccount->id);
    }

    // Force regenerate should not duplicate monthly journals
    GenerateYearlyForAsset::run(asset: $asset, targetYear: 2026, force: true);

    $transactionsAfter = Transaction::query()
        ->where('type', 'journal')
        ->where('company_id', $company->id)
        ->where('meta->addon', 'asset_depreciation')
        ->where('meta->asset_id', $asset->id)
        ->where('meta->fiscal_year', 2026)
        ->get();

    expect($transactionsAfter)->toHaveCount(12);
});

