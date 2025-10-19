<?php

namespace App\Actions\Addons;

use App\Models\Addons\Asset;
use App\Models\Addons\DepreciationRecord;
use App\Models\Accounting\JournalEntry;
use App\Enums\Accounting\JournalEntryType;
use App\Models\Accounting\Transaction;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class GenerateYearlyForAsset
{
    public static function run(
        Asset $asset,
        ?int $targetYear = null,
        bool $force = false
    ): array {
        $targetYear ??= now()->year;

        if ((int)$asset->acquisition_date->format('Y') > $targetYear) {
            return ['created' => [], 'skipped' => []];
        }

        $method = $asset->depreciation_method?->value
            ?? $asset->group?->default_method
            ?? 'garis_lurus';

        $lifeMonths = $asset->useful_life_months
            ?? $asset->group?->useful_life_months
            ?? 0;

        $disposalYear = null;
        if (method_exists($asset, 'disposals') && $asset->disposals()->exists()) {
            $disposalYear = (int) $asset->disposals()->max('disposal_date')->format('Y');
        }

        $startYear = (int) $asset->acquisition_date->format('Y');
        $startMonth = (int) $asset->acquisition_date->format('m');

        $created = [];
        $skipped = [];

        DB::transaction(function () use (
            $asset,
            $startYear,
            $startMonth,
            $targetYear,
            $force,
            $method,
            $lifeMonths,
            $disposalYear,
            &$created,
            &$skipped
        ) {
            $opening = $asset->acquisition_cost;

            for ($year = $startYear; $year <= $targetYear; $year++) {

                if ($disposalYear && $year > $disposalYear) {
                    $skipped[] = $year;
                    continue;
                }

                $existing = DepreciationRecord::where('asset_id', $asset->id)
                    ->where('fiscal_year', $year)
                    ->first();

                if ($existing && !$force) {
                    $opening = $existing->closing_fiscal_book_value;
                    $skipped[] = $year;
                    continue;
                }

                // --- Hitung prorata bulan pertama ---
                if ($year == $startYear) {
                    $monthsInYear = 12 - ($startMonth - 1); // misal beli Maret → 10 bulan
                } else {
                    $monthsInYear = 12;
                }

                // Hitung sisa umur
                $monthsUsedBeforeYear = max(
                    0,
                    $asset->acquisition_date->diffInMonths(Carbon::create($year, 1, 1))
                );

                $remainingLifeAtYearStart = max(0, $lifeMonths - $monthsUsedBeforeYear);
                if ($remainingLifeAtYearStart <= 0 || $opening <= $asset->salvage_value) {
                    $skipped[] = $year;
                    continue;
                }

                // Batas bulan maksimum sesuai sisa umur
                $monthsInYear = min($monthsInYear, $remainingLifeAtYearStart);

                // Hitung penyusutan
                $monthly = [];
                $deprExpense = 0.0;

                if ($method === 'garis_lurus') {
                    $base = max(0, $asset->acquisition_cost - $asset->salvage_value);
                    $perMonth = $lifeMonths > 0 ? ($base / $lifeMonths) : 0.0;

                    for ($m = 1; $m <= $monthsInYear; $m++) {
                        if ($opening > $asset->salvage_value) {
                            $charge = round($perMonth, 2);
                            $charge = min($charge, max(0, $opening - $asset->salvage_value));
                            $monthly[$m] = $charge;
                            $deprExpense += $charge;
                            $opening -= $charge;
                        } else {
                            $monthly[$m] = 0.0;
                        }
                    }

                    $opening = round(($existing->opening_fiscal_book_value ?? ($opening + $deprExpense)), 2);
                } else {
                    $annualRate = (float) ($asset->group->declining_rate ?? 0.25);
                    $monthlyRate = $annualRate / 12;
                    $book = $opening;

                    for ($m = 1; $m <= $monthsInYear; $m++) {
                        if ($book > $asset->salvage_value) {
                            $charge = round($book * $monthlyRate, 2);
                            $charge = min($charge, max(0, $book - $asset->salvage_value));
                            $book -= $charge;
                            $monthly[$m] = $charge;
                            $deprExpense += $charge;
                        } else {
                            $monthly[$m] = 0.0;
                        }
                    }
                }

                $closing = max($asset->salvage_value, round($opening - $deprExpense, 2));

                $record = DepreciationRecord::updateOrCreate(
                    ['asset_id' => $asset->id, 'fiscal_year' => $year],
                    [
                        'opening_fiscal_book_value'   => $opening,
                        'depreciation_expense'        => $deprExpense,
                        'closing_fiscal_book_value'   => $closing,
                        'fiscal_method'               => $method,
                        'commercial_method'           => $method,
                        'monthly_breakdown'           => $monthly,
                    ]
                );

                $created[] = $record;
                if ($asset->group && $asset->group->contraAsset && $asset->group->assetAccount) {
                    $transaction = Transaction::create([
                        'bank_account_id' => null,
                        'type'            => 'journal',
                        'description'     => 'Asset depreciation calculation',
                        'posted_at'       => now(),
                        'amount'          => $deprExpense,
                        'reviewed'        => false
                    ]);

                    JournalEntry::create([
                        'transaction_id' => $transaction->id,
                        'account_id'     => $asset->group->contraAsset->id,
                        'type'           => JournalEntryType::Debit,
                        'amount'         => $deprExpense,
                        'description'    => "Depreciation of asset {$asset->name} for year {$year}",
                    ]);

                    JournalEntry::create([
                        'transaction_id' => $transaction->id,
                        'account_id'     => $asset->group->assetAccount->id,
                        'type'           => JournalEntryType::Credit,
                        'amount'         => $deprExpense,
                        'description'    => "Depreciation expense for asset {$asset->name} for year {$year}",
                    ]);
                }

                $opening = $closing;
                if ($closing <= $asset->salvage_value) {
                    $asset->update(['status' => 'fully_depreciated']);
                }
            }
        });

        return compact('created', 'skipped');
    }
}
