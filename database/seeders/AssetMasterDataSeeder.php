<?php

namespace Database\Seeders;

use App\Models\Accounting\Account;
use App\Models\Accounting\Transaction;
use App\Models\Addons\Asset;
use App\Models\Addons\AssetDisposal;
use App\Models\Addons\AssetGroup;
use App\Models\Addons\DepreciationRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Overwrites asset master data (groups + assets) per company from snapshot files.
 *
 * WARNING: Destructive for the configured companies — deletes depreciation records,
 * asset disposals, assets, asset groups, and soft-deletes related asset journals.
 * Does NOT run from DatabaseSeeder by default. Run explicitly:
 *
 *   php artisan db:seed --class=AssetMasterDataSeeder
 */
class AssetMasterDataSeeder extends Seeder
{
    /** Ubah di server jika company_id berbeda. */
    private const COMPANY_A_ID = 1;

    /** Ubah di server jika company_id berbeda. */
    private const COMPANY_B_ID = 3;

    private const ASSET_JOURNAL_ADDONS = [
        'asset_depreciation',
        'asset_initial_recognition',
    ];

    public function run(): void
    {
        $companies = [
            self::COMPANY_A_ID => database_path('seeders/data/asset_master_company_1.php'),
            self::COMPANY_B_ID => database_path('seeders/data/asset_master_company_3.php'),
        ];

        foreach ($companies as $companyId => $dataPath) {
            if (! is_file($dataPath)) {
                $this->command?->warn("Skipping company {$companyId}: data file not found.");

                continue;
            }

            $data = require $dataPath;

            DB::transaction(function () use ($companyId, $data): void {
                $this->cleanupCompany($companyId);
                $this->seedCompany($companyId, $data);
            });

            $this->command?->info("Asset master data seeded for company_id={$companyId}.");
        }
    }

    private function cleanupCompany(int $companyId): void
    {
        $assetIds = Asset::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->pluck('id');

        $this->deleteAssetJournals($companyId, $assetIds);

        DepreciationRecord::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->delete();

        if ($assetIds->isNotEmpty()) {
            AssetDisposal::query()
                ->whereIn('asset_id', $assetIds)
                ->delete();
        }

        Asset::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->delete();

        AssetGroup::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->delete();
    }

    private function deleteAssetJournals(int $companyId, Collection $assetIds): void
    {
        if ($assetIds->isEmpty()) {
            return;
        }

        Transaction::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('type', 'journal')
            ->whereIn('meta->addon', self::ASSET_JOURNAL_ADDONS)
            ->whereIn('meta->asset_id', $assetIds)
            ->with('journalEntries')
            ->get()
            ->each(function (Transaction $transaction): void {
                $transaction->journalEntries->each->delete();
                $transaction->delete();
            });
    }

    /**
     * @param  array{groups: list<array<string, mixed>>, assets: list<array<string, mixed>>}  $data
     */
    private function seedCompany(int $companyId, array $data): void
    {
        $groupIdsByName = [];

        foreach ($data['groups'] ?? [] as $groupRow) {
            $name = (string) $groupRow['name'];
            $groupIdsByName[$name] = AssetGroup::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'name' => $name,
                'description' => $groupRow['description'] ?? null,
                'useful_life_months' => $groupRow['useful_life_months'] ?? null,
                'default_method' => $groupRow['default_method'] ?? null,
                'asset_account_id' => $this->resolveAccountIdByName(
                    $companyId,
                    (string) $groupRow['asset_account_name'],
                    'asset expense'
                ),
                'contra_account_id' => $this->resolveAccountIdByName(
                    $companyId,
                    (string) $groupRow['contra_account_name'],
                    'contra asset'
                ),
            ])->id;
        }

        foreach ($data['assets'] ?? [] as $assetRow) {
            $groupName = (string) $assetRow['group_name'];

            if (! isset($groupIdsByName[$groupName])) {
                throw new RuntimeException(
                    "Asset \"{$assetRow['name']}\" references unknown group \"{$groupName}\" for company_id={$companyId}."
                );
            }

            Asset::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'group_id' => $groupIdsByName[$groupName],
                'asset_code' => $assetRow['asset_code'] ?? null,
                'name' => $assetRow['name'],
                'acquisition_date' => $assetRow['acquisition_date'],
                'acquisition_cost' => $assetRow['acquisition_cost'],
                'useful_life_months' => $assetRow['useful_life_months'] ?? null,
                'salvage_value' => $assetRow['salvage_value'] ?? 0,
                'depreciation_method' => $assetRow['depreciation_method'] ?? null,
                'status' => $assetRow['status'] ?? 'active',
                'meta' => $assetRow['meta'] ?? null,
            ]);
        }
    }

    private function resolveAccountIdByName(int $companyId, string $name, string $label): int
    {
        $accountIds = Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('name', $name)
            ->pluck('id');

        if ($accountIds->isEmpty()) {
            throw new RuntimeException(
                "Account not found for company_id={$companyId} ({$label}): name \"{$name}\"."
            );
        }

        if ($accountIds->count() > 1) {
            throw new RuntimeException(
                "Multiple accounts found for company_id={$companyId} ({$label}): name \"{$name}\"."
            );
        }

        return (int) $accountIds->first();
    }
}
