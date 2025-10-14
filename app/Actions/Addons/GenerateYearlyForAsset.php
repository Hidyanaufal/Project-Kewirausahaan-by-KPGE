<?php

namespace App\Actions\Addons;

use App\Models\Addons\Asset;
use App\Models\Addons\DepreciationRecord;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class GenerateYearlyForAsset
{
    /**
     * Generate depreciation from acquisition year up to target year (default: current year).
     * - Skips years that already have a record (idempotent).
     * - Prorates first/last year based on remaining useful life months.
     * - Stops at salvage value / when useful life is exhausted.
     *
     * @return array{created: array<int,DepreciationRecord>, skipped: array<int,int>} created records & skipped years
     */
    public static function run(
        Asset $asset,
        ?int $targetYear = null,
        bool $force = false
    ): array {
        $targetYear ??= now()->year;

        // If acquisition is in the future relative to target year, nothing to do.
        if ((int)$asset->acquisition_date->format('Y') > $targetYear) {
            return ['created' => [], 'skipped' => []];
        }

        // Params with sensible fallbacks
        $method = $asset->depreciation_method?->value
            ?? $asset->group?->default_method
            ?? 'garis_lurus';

        $lifeMonths = $asset->useful_life_months
            ?? $asset->group?->useful_life_months
            ?? 0;

        // Optional: stop generating after disposal year (if you track disposals)
        $disposalYear = null;
        if (method_exists($asset, 'disposals') && $asset->disposals()->exists()) {
            $disposalYear = (int) $asset->disposals()->max('disposal_date')->format('Y');
        }

        $startYear = (int) $asset->acquisition_date->format('Y');

        $created = [];
        $skipped = [];

        DB::transaction(function () use (
            $asset,
            $startYear,
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
                    $skipped[] = $year; // no depreciation after disposal
                    continue;
                }

                // If record exists: use it as opening for next loop; optionally skip/keep
                $existing = DepreciationRecord::where('asset_id', $asset->id)
                    ->where('fiscal_year', $year)
                    ->first();

                if ($existing && !$force) {
                    $opening = $existing->closing_fiscal_book_value; // chain forward
                    $skipped[] = $year;
                    continue;
                }

                // Compute months remaining at the start of this year
                $monthsUsedBeforeYear = max(
                    0,
                    $asset->acquisition_date->diffInMonths(Carbon::create($year, 1, 1))
                );
                $remainingLifeAtYearStart = max(0, $lifeMonths - $monthsUsedBeforeYear);
                if ($remainingLifeAtYearStart <= 0 || $opening <= $asset->salvage_value) {
                    $skipped[] = $year;
                    continue; // no more depreciation
                }

                // Prorate months for this year: max 12, bounded by remaining life
                $monthsInYear = min(12, $remainingLifeAtYearStart);

                // Calculate expense
                $monthly = [];
                $deprExpense = 0.0;

                if ($method === 'garis_lurus') {
                    $base = max(0, $asset->acquisition_cost - $asset->salvage_value);
                    $perMonth = $lifeMonths > 0 ? ($base / $lifeMonths) : 0.0;
                    for ($m = 1; $m <= 12; $m++) {
                        if ($m <= $monthsInYear && $opening > $asset->salvage_value) {
                            $charge = round($perMonth, 2);
                            // clamp so we don't go below salvage_value
                            $charge = min($charge, max(0, $opening - $asset->salvage_value));
                            $monthly[$m] = $charge;
                            $deprExpense += $charge;
                            $opening -= $charge; // step-down so clamping works per month
                        } else {
                            $monthly[$m] = 0.0;
                        }
                    }
                    // restore opening to the true opening at start of year
                    $opening = round(($existing->opening_fiscal_book_value ?? ($opening + $deprExpense)), 2);
                } else {
                    // saldo menurun — recommend store rate in asset_groups (e.g., declining_rate)
                    $annualRate = (float) ($asset->group->declining_rate ?? 0.25);
                    $monthlyRate = $annualRate / 12;
                    $book = $opening;
                    for ($m = 1; $m <= 12; $m++) {
                        if ($m <= $monthsInYear && $book > $asset->salvage_value) {
                            $charge = round($book * $monthlyRate, 2);
                            // clamp to salvage floor
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

                // Idempotency: if exists and !force, we skipped above; if force, update
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

                // Prepare opening for next year in the loop
                $opening = $closing;

                // Optional: mark fully depreciated if reached salvage
                if ($closing <= $asset->salvage_value) {
                    $asset->update(['status' => 'fully_depreciated']);
                    // We could break here, but keep looping to mark later years as skipped.
                }
            }
        });

        return compact('created', 'skipped');
    }
}
