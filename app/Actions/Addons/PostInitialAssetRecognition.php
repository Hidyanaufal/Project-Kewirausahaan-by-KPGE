<?php

namespace App\Actions\Addons;

use App\Enums\Accounting\JournalEntryType;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
use App\Models\Addons\Asset;

class PostInitialAssetRecognition
{
    private const META_ADDON_KEY = 'addon';

    private const META_ADDON_VALUE = 'asset_initial_recognition';

    private const META_ASSET_ID_KEY = 'asset_id';

    /**
     * @return array{posted: bool, message: string}
     */
    public static function run(Asset $asset): array
    {
        $asset->loadMissing('group.assetAccount');

        if (! $asset->group || ! $asset->group->assetAccount) {
            return [
                'posted' => false,
                'message' => 'Asset group has no asset account configured.',
            ];
        }

        $exists = Transaction::query()
            ->where('company_id', $asset->company_id)
            ->where('type', 'journal')
            ->where('meta->' . self::META_ADDON_KEY, self::META_ADDON_VALUE)
            ->where('meta->' . self::META_ASSET_ID_KEY, $asset->id)
            ->exists();

        if ($exists) {
            return [
                'posted' => false,
                'message' => 'Initial recognition journal already exists for this asset.',
            ];
        }

        $value = $asset->acquisition_cost;

        $transaction = Transaction::create([
            'company_id' => $asset->company_id,
            'bank_account_id' => null,
            'type' => 'journal',
            'description' => "Initial asset recognition for {$asset->name}",
            'posted_at' => now(),
            'amount' => $value,
            'reviewed' => false,
            'meta' => [
                self::META_ADDON_KEY => self::META_ADDON_VALUE,
                self::META_ASSET_ID_KEY => $asset->id,
            ],
        ]);

        JournalEntry::create([
            'company_id' => $asset->company_id,
            'transaction_id' => $transaction->id,
            'account_id' => $asset->group->assetAccount->id,
            'type' => JournalEntryType::Debit,
            'amount' => $value,
            'description' => "Asset acquisition - {$asset->name}",
        ]);

        return [
            'posted' => true,
            'message' => 'Initial asset recognition journal posted.',
        ];
    }
}
