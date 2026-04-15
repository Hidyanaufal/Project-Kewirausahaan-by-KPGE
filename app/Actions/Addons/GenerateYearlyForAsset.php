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
    private const META_ADDON_KEY = 'addon';
    private const META_ADDON_VALUE = 'asset_depreciation';
    private const META_ASSET_ID_KEY = 'asset_id';
    private const META_FISCAL_YEAR_KEY = 'fiscal_year';
    private const META_FISCAL_MONTH_KEY = 'fiscal_month';

    public static function run(
        Asset $asset,
        ?int $targetYear = null,
        bool $force = false
    ): array {
        $now = Carbon::now();
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('m');
        $isEndOfMonth = $now->isLastOfMonth();

        // Jika belum akhir bulan, penyusutan hanya sampai bulan sebelumnya.
        $maxMonthInCurrentYear = $isEndOfMonth ? $currentMonth : ($currentMonth - 1);

        $targetYear ??= $currentYear;
        $targetYear = min($targetYear, $currentYear);

        if ((int)$asset->acquisition_date->format('Y') > $targetYear) {
            return ['created' => [], 'skipped' => []];
        }

        // Aturan start:
        // - Jika tanggal akuisisi > 15: mulai depresiasi bulan berikutnya
        // - Jika tanggal akuisisi <= 15: mulai depresiasi bulan yang sama
        $acqDate = $asset->acquisition_date instanceof Carbon
            ? $asset->acquisition_date->copy()
            : Carbon::parse($asset->acquisition_date);

        $deprStartDate = $acqDate->copy()->startOfMonth();
        if ((int) $acqDate->format('d') > 15) {
            $deprStartDate = $deprStartDate->addMonth();
        }

        $method = $asset->depreciation_method?->value
            ?? $asset->group?->default_method?->value
            ?? 'garis_lurus';

        $lifeMonths = $asset->useful_life_months
            ?? $asset->group?->useful_life_months
            ?? 0;

        $disposalYear = null;
        if (method_exists($asset, 'disposals') && $asset->disposals()->exists()) {
            $disposalYear = (int) $asset->disposals()->max('disposal_date')->format('Y');
        }

        $startYear = (int) $deprStartDate->format('Y');
        $startMonth = (int) $deprStartDate->format('m');

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
            $deprStartDate,
            $currentYear,
            $maxMonthInCurrentYear,
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

                $yearStartMonth = $year === $startYear ? $startMonth : 1;
                $yearEndMonth = 12;

                // Batasi untuk tahun berjalan (atau targetYear==tahun berjalan) sesuai aturan "belum akhir bulan".
                if ($year === $currentYear) {
                    $yearEndMonth = min($yearEndMonth, $maxMonthInCurrentYear);
                }

                if ($yearEndMonth < $yearStartMonth) {
                    $skipped[] = $year;
                    continue;
                }

                // Hitung sisa umur
                $monthsUsedBeforeYear = max(
                    0,
                    $deprStartDate->diffInMonths(Carbon::create($year, 1, 1))
                );

                $remainingLifeAtYearStart = max(0, $lifeMonths - $monthsUsedBeforeYear);
                if ($remainingLifeAtYearStart <= 0 || $opening <= $asset->salvage_value) {
                    $skipped[] = $year;
                    continue;
                }

                $maxMonthsByDate = ($yearEndMonth - $yearStartMonth) + 1;
                $monthsToRun = min($maxMonthsByDate, $remainingLifeAtYearStart);

                // Hitung penyusutan
                $monthly = [];
                $deprExpense = 0.0;

                if ($method === 'garis_lurus') {
                    $base = max(0, $asset->acquisition_cost - $asset->salvage_value);
                    $perMonth = $lifeMonths > 0 ? ($base / $lifeMonths) : 0.0;

                    for ($i = 0; $i < $monthsToRun; $i++) {
                        $m = $yearStartMonth + $i;
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

                    for ($i = 0; $i < $monthsToRun; $i++) {
                        $m = $yearStartMonth + $i;
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
                    if ($force || $existing) {
                        self::deleteMonthlyDepreciationJournals(assetId: $asset->id, fiscalYear: $year);
                    }

                    self::createMonthlyDepreciationJournals(
                        asset: $asset,
                        fiscalYear: $year,
                        monthlyBreakdown: $monthly
                    );
                }

                $opening = $closing;
                if ($closing <= $asset->salvage_value) {
                    $asset->update(['status' => 'fully_depreciated']);
                }
            }
        });

        return compact('created', 'skipped');
    }

    private static function deleteMonthlyDepreciationJournals(int $assetId, int $fiscalYear): void
    {
        Transaction::query()
            ->where('type', 'journal')
            ->where("meta->" . self::META_ADDON_KEY, self::META_ADDON_VALUE)
            ->where("meta->" . self::META_ASSET_ID_KEY, $assetId)
            ->where("meta->" . self::META_FISCAL_YEAR_KEY, $fiscalYear)
            ->with('journalEntries')
            ->get()
            ->each(function (Transaction $transaction) {
                $transaction->journalEntries()->delete();
                $transaction->delete();
            });
    }

    private static function createMonthlyDepreciationJournals(Asset $asset, int $fiscalYear, array $monthlyBreakdown): void
    {
        foreach ($monthlyBreakdown as $calendarMonth => $rawAmount) {
            $calendarMonth = (int) $calendarMonth;

            if ($calendarMonth < 1 || $calendarMonth > 12) {
                continue;
            }

            // Di modul accounting, `journal_entries.amount` disimpan sebagai integer (cents).
            // Nilai `monthly_breakdown` berasal dari kalkulasi atas `acquisition_cost` yang juga tersimpan dalam cents,
            // jadi jangan dikonversi lagi (akan mengalikan 100x).
            $amountInCents = (int) round((float) $rawAmount);

            if ($amountInCents <= 0) {
                continue;
            }

            $postedAt = Carbon::create($fiscalYear, $calendarMonth, 1)->endOfMonth();

            $transaction = Transaction::create([
                'company_id' => $asset->company_id,
                'bank_account_id' => null,
                'type' => 'journal',
                'description' => "Asset depreciation - {$asset->name} ({$fiscalYear}-" . str_pad((string) $calendarMonth, 2, '0', STR_PAD_LEFT) . ')',
                'posted_at' => $postedAt,
                'amount' => $amountInCents,
                'reviewed' => false,
                'meta' => [
                    self::META_ADDON_KEY => self::META_ADDON_VALUE,
                    self::META_ASSET_ID_KEY => $asset->id,
                    self::META_FISCAL_YEAR_KEY => $fiscalYear,
                    self::META_FISCAL_MONTH_KEY => (int) $calendarMonth,
                ],
            ]);

            $description = "Depreciation of asset {$asset->name} for {$fiscalYear}-" . str_pad((string) $calendarMonth, 2, '0', STR_PAD_LEFT);

            JournalEntry::create([
                'company_id' => $asset->company_id,
                'transaction_id' => $transaction->id,
                'account_id' => $asset->group->assetAccount->id,
                'type' => JournalEntryType::Debit,
                'amount' => $amountInCents,
                'description' => $description,
            ]);

            JournalEntry::create([
                'company_id' => $asset->company_id,
                'transaction_id' => $transaction->id,
                'account_id' => $asset->group->contraAsset->id,
                'type' => JournalEntryType::Credit,
                'amount' => $amountInCents,
                'description' => $description,
            ]);
        }
    }
}
