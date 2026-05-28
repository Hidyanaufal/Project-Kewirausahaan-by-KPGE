<?php

use App\Actions\Addons\GenerateYearlyForAsset;
use App\Actions\Addons\PostInitialAssetRecognition;
use App\Enums\Accounting\JournalEntryType;
use App\Models\Accounting\Account;
use App\Models\Accounting\Transaction;
use App\Models\Addons\Asset;
use App\Models\Addons\AssetGroup;
use App\Models\Company;
use Carbon\Carbon;

it('creates balanced monthly depreciation journals (debit asset, credit contra) and is idempotent on force', function () {
    Carbon::setTestNow(Carbon::create(2026, 12, 31, 0, 0, 0));

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

    expect(Transaction::query()
        ->where('company_id', $company->id)
        ->where('description', 'Initial asset recognition for Test Asset')
        ->count())->toBe(0);

    GenerateYearlyForAsset::run(asset: $asset, targetYear: 2026, force: true);

    expect(Transaction::query()
        ->where('company_id', $company->id)
        ->where('description', 'Initial asset recognition for Test Asset')
        ->count())->toBe(0);

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

it('updates monthly depreciation journals when acquisition_cost changes (no duplicates, only values change)', function () {
    Carbon::setTestNow(Carbon::create(2026, 12, 31, 0, 0, 0));

    $company = Company::firstOrFail();

    $assetAccount = Account::factory()->create([
        'company_id' => $company->id,
        'name' => 'Asset Account - Update Test',
    ]);

    $contraAccount = Account::factory()->create([
        'company_id' => $company->id,
        'name' => 'Contra Asset Account - Update Test',
    ]);

    $group = AssetGroup::create([
        'company_id' => $company->id,
        'name' => 'Test Group Update',
        'useful_life_months' => 12,
        'default_method' => 'garis_lurus',
        'asset_account_id' => $assetAccount->id,
        'contra_account_id' => $contraAccount->id,
    ]);

    /** @var Asset $asset */
    $asset = Asset::create([
        'company_id' => $company->id,
        'group_id' => $group->id,
        'asset_code' => 'AST-UPD-001',
        'name' => 'Update Cost Asset',
        'acquisition_date' => '2026-01-01',
        'acquisition_cost' => 1200.00,
        'salvage_value' => 0.00,
        'useful_life_months' => 12,
        'depreciation_method' => 'garis_lurus',
        'status' => 'active',
    ]);

    GenerateYearlyForAsset::run(asset: $asset, targetYear: 2026, force: true);

    $txBefore = Transaction::query()
        ->where('type', 'journal')
        ->where('company_id', $company->id)
        ->where('meta->addon', 'asset_depreciation')
        ->where('meta->asset_id', $asset->id)
        ->where('meta->fiscal_year', 2026)
        ->get();

    expect($txBefore)->toHaveCount(12);
    $amountsBefore = $txBefore->pluck('amount')->sort()->values()->all();

    // Update acquisition cost and regenerate (non-force) - should recalc values without duplicating months.
    $asset->update(['acquisition_cost' => 2400.00]);
    GenerateYearlyForAsset::run(asset: $asset, targetYear: 2026, force: false);

    $txAfter = Transaction::query()
        ->where('type', 'journal')
        ->where('company_id', $company->id)
        ->where('meta->addon', 'asset_depreciation')
        ->where('meta->asset_id', $asset->id)
        ->where('meta->fiscal_year', 2026)
        ->get();

    expect($txAfter)->toHaveCount(12);

    $amountsAfter = $txAfter->pluck('amount')->sort()->values()->all();
    expect($amountsAfter)->not->toBe($amountsBefore);

    // Ensure still balanced and points to correct accounts
    foreach ($txAfter as $transaction) {
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
});

it('regenerates using latest acquisition_cost even if Asset instance is stale', function () {
    Carbon::setTestNow(Carbon::create(2026, 12, 31, 0, 0, 0));

    $company = Company::firstOrFail();

    $assetAccount = Account::factory()->create([
        'company_id' => $company->id,
        'name' => 'Asset Account - Stale Test',
    ]);

    $contraAccount = Account::factory()->create([
        'company_id' => $company->id,
        'name' => 'Contra Asset Account - Stale Test',
    ]);

    $group = AssetGroup::create([
        'company_id' => $company->id,
        'name' => 'Test Group Stale',
        'useful_life_months' => 12,
        'default_method' => 'garis_lurus',
        'asset_account_id' => $assetAccount->id,
        'contra_account_id' => $contraAccount->id,
    ]);

    $asset = Asset::create([
        'company_id' => $company->id,
        'group_id' => $group->id,
        'asset_code' => 'AST-STALE-001',
        'name' => 'Stale Asset',
        'acquisition_date' => '2026-01-01',
        'acquisition_cost' => 1200.00,
        'salvage_value' => 0.00,
        'useful_life_months' => 12,
        'depreciation_method' => 'garis_lurus',
        'status' => 'active',
    ]);

    // Ambil instance stale (tanpa refresh setelah update DB).
    $stale = Asset::findOrFail($asset->id);

    GenerateYearlyForAsset::run(asset: $stale, targetYear: 2026, force: true);
    $before = Transaction::query()
        ->where('type', 'journal')
        ->where('company_id', $company->id)
        ->where('meta->addon', 'asset_depreciation')
        ->where('meta->asset_id', $asset->id)
        ->where('meta->fiscal_year', 2026)
        ->pluck('amount')
        ->sort()
        ->values()
        ->all();

    // Update DB menggunakan query builder (stale instance tidak berubah).
    Asset::query()->whereKey($asset->id)->update(['acquisition_cost' => 2400.00]);

    // Jalankan lagi pakai instance stale yang masih punya acquisition_cost lama.
    GenerateYearlyForAsset::run(asset: $stale, targetYear: 2026, force: true);

    $after = Transaction::query()
        ->where('type', 'journal')
        ->where('company_id', $company->id)
        ->where('meta->addon', 'asset_depreciation')
        ->where('meta->asset_id', $asset->id)
        ->where('meta->fiscal_year', 2026)
        ->pluck('amount')
        ->sort()
        ->values()
        ->all();

    expect($after)->not->toBe($before);
});

it('posts initial recognition only via action and is idempotent', function () {
    $company = Company::firstOrFail();

    $assetAccount = Account::factory()->create([
        'company_id' => $company->id,
        'name' => 'Asset Account - Initial Test',
    ]);

    $contraAccount = Account::factory()->create([
        'company_id' => $company->id,
        'name' => 'Contra Asset Account - Initial Test',
    ]);

    $group = AssetGroup::create([
        'company_id' => $company->id,
        'name' => 'Test Group Initial',
        'useful_life_months' => 12,
        'default_method' => 'garis_lurus',
        'asset_account_id' => $assetAccount->id,
        'contra_account_id' => $contraAccount->id,
    ]);

    /** @var Asset $asset */
    $asset = Asset::create([
        'company_id' => $company->id,
        'group_id' => $group->id,
        'asset_code' => 'AST-INIT-001',
        'name' => 'Init Journal Asset',
        'acquisition_date' => '2026-02-01',
        'acquisition_cost' => 500.00,
        'salvage_value' => 0,
        'useful_life_months' => 12,
        'depreciation_method' => 'garis_lurus',
        'status' => 'active',
    ]);

    $first = PostInitialAssetRecognition::run(asset: $asset);
    expect($first['posted'])->toBeTrue();

    $tx = Transaction::query()
        ->where('company_id', $company->id)
        ->where('meta->addon', 'asset_initial_recognition')
        ->where('meta->asset_id', $asset->id)
        ->first();

    expect($tx)->not->toBeNull()
        ->and($tx->description)->toBe('Initial asset recognition for Init Journal Asset');

    $second = PostInitialAssetRecognition::run(asset: $asset);
    expect($second['posted'])->toBeFalse();

    expect(Transaction::query()
        ->where('company_id', $company->id)
        ->where('meta->addon', 'asset_initial_recognition')
        ->where('meta->asset_id', $asset->id)
        ->count())->toBe(1);
});

