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
                $monthsToRunTotal = min($maxMonthsByDate, $remainingLifeAtYearStart);

                // Nilai buku awal tahun ini adalah hasil closing tahun sebelumnya (atau acquisition_cost untuk tahun pertama).
                $yearOpening = $opening;

                // Siapkan breakdown bulanan, bisa berasal dari existing (extend) atau hitung ulang penuh.
                $monthly = [];
                $didChange = false;

                // Helper kecil untuk normalisasi bulan existing.
                $normalizeExistingMonthly = function (?array $source) use ($yearStartMonth, $yearEndMonth): array {
                    $out = [];
                    foreach (($source ?? []) as $m => $amt) {
                        $m = (int) $m;
                        if ($m < $yearStartMonth || $m > $yearEndMonth) {
                            continue;
                        }
                        if ($m < 1 || $m > 12) {
                            continue;
                        }
                        $out[$m] = (float) $amt;
                    }
                    ksort($out);
                    return $out;
                };

                // Jika ada record existing dan tidak force, coba extend untuk bulan yang belum ada.
                if ($existing && !$force) {
                    $monthly = $normalizeExistingMonthly((array) $existing->monthly_breakdown);

                    // Trim jika existing punya bulan melebihi batas useful life untuk tahun ini.
                    $lastAllowedMonth = $yearStartMonth + $monthsToRunTotal - 1;
                    foreach (array_keys($monthly) as $m) {
                        if ($m > $lastAllowedMonth) {
                            unset($monthly[$m]);
                        }
                    }

                    $monthsAlreadyRecorded = count($monthly);
                    $remainingLifeAfterExisting = max(0, $remainingLifeAtYearStart - $monthsAlreadyRecorded);

                    $existingExpense = array_sum($monthly);
                    $book = max($asset->salvage_value, round($yearOpening - $existingExpense, 2));

                    // Cari bulan yang hilang, isi berurutan dari awal range sampai batas yang boleh.
                    $monthsGenerated = 0;
                    if ($remainingLifeAfterExisting > 0 && $book > $asset->salvage_value) {
                        if ($method === 'garis_lurus') {
                            $base = max(0, $asset->acquisition_cost - $asset->salvage_value);
                            $perMonth = $lifeMonths > 0 ? ($base / $lifeMonths) : 0.0;

                            for ($m = $yearStartMonth; $m <= $lastAllowedMonth; $m++) {
                                if (array_key_exists($m, $monthly)) {
                                    continue;
                                }
                                if ($monthsGenerated >= $remainingLifeAfterExisting) {
                                    break;
                                }
                                if ($book <= $asset->salvage_value) {
                                    break;
                                }
                                $charge = round($perMonth, 2);
                                $charge = min($charge, max(0, $book - $asset->salvage_value));
                                $monthly[$m] = $charge;
                                $book = round($book - $charge, 2);
                                $monthsGenerated++;
                            }
                        } else {
                            $annualRate = (float) ($asset->group->declining_rate ?? 0.25);
                            $monthlyRate = $annualRate / 12;

                            for ($m = $yearStartMonth; $m <= $lastAllowedMonth; $m++) {
                                if (array_key_exists($m, $monthly)) {
                                    continue;
                                }
                                if ($monthsGenerated >= $remainingLifeAfterExisting) {
                                    break;
                                }
                                if ($book <= $asset->salvage_value) {
                                    break;
                                }
                                $charge = round($book * $monthlyRate, 2);
                                $charge = min($charge, max(0, $book - $asset->salvage_value));
                                $book = round($book - $charge, 2);
                                $monthly[$m] = $charge;
                                $monthsGenerated++;
                            }
                        }
                    }

                    ksort($monthly);

                    // Cek apakah record ini sudah "lengkap" sesuai range & useful life.
                    $expectedMonths = [];
                    for ($m = $yearStartMonth; $m <= ($yearStartMonth + $monthsToRunTotal - 1); $m++) {
                        $expectedMonths[] = $m;
                    }
                    $missingMonths = array_filter($expectedMonths, fn ($m) => !array_key_exists($m, $monthly));

                    if (!empty($missingMonths)) {
                        // Tidak bisa diisi (mis. sudah salvage/remaining life habis), tapi tetap treat sebagai tidak berubah
                        // jika memang existing sudah sama. Kalau ada generate yang berhasil di atas, akan terdeteksi via diff.
                    }

                    // Detect perubahan dibanding existing.
                    $existingNormalized = $normalizeExistingMonthly((array) $existing->monthly_breakdown);
                    foreach (array_keys($existingNormalized) as $m) {
                        if ($m > ($yearStartMonth + $monthsToRunTotal - 1)) {
                            unset($existingNormalized[$m]);
                        }
                    }
                    ksort($existingNormalized);

                    $didChange = $monthly !== $existingNormalized
                        || (float) $existing->opening_fiscal_book_value !== (float) $yearOpening;
                } else {
                    // Hitung ulang penuh (force atau belum ada record).
                    $book = $yearOpening;
                    if ($method === 'garis_lurus') {
                        $base = max(0, $asset->acquisition_cost - $asset->salvage_value);
                        $perMonth = $lifeMonths > 0 ? ($base / $lifeMonths) : 0.0;

                        for ($i = 0; $i < $monthsToRunTotal; $i++) {
                            $m = $yearStartMonth + $i;
                            if ($book > $asset->salvage_value) {
                                $charge = round($perMonth, 2);
                                $charge = min($charge, max(0, $book - $asset->salvage_value));
                                $monthly[$m] = $charge;
                                $book = round($book - $charge, 2);
                            } else {
                                $monthly[$m] = 0.0;
                            }
                        }
                    } else {
                        $annualRate = (float) ($asset->group->declining_rate ?? 0.25);
                        $monthlyRate = $annualRate / 12;

                        for ($i = 0; $i < $monthsToRunTotal; $i++) {
                            $m = $yearStartMonth + $i;
                            if ($book > $asset->salvage_value) {
                                $charge = round($book * $monthlyRate, 2);
                                $charge = min($charge, max(0, $book - $asset->salvage_value));
                                $book = round($book - $charge, 2);
                                $monthly[$m] = $charge;
                            } else {
                                $monthly[$m] = 0.0;
                            }
                        }
                    }

                    $didChange = true;
                }

                $deprExpense = (float) array_sum($monthly);
                $closing = max($asset->salvage_value, round($yearOpening - $deprExpense, 2));

                // Jika existing & tidak force & tidak ada perubahan, tetap skip tahun ini.
                if ($existing && !$force && !$didChange) {
                    $opening = $existing->closing_fiscal_book_value;
                    $skipped[] = $year;
                    continue;
                }

                $record = DepreciationRecord::updateOrCreate(
                    ['asset_id' => $asset->id, 'fiscal_year' => $year],
                    [
                        'opening_fiscal_book_value'   => $yearOpening,
                        'depreciation_expense'        => $deprExpense,
                        'closing_fiscal_book_value'   => $closing,
                        'fiscal_method'               => $method,
                        'commercial_method'           => $method,
                        'monthly_breakdown'           => $monthly,
                    ]
                );

                $created[] = $record;
                if ($asset->group && $asset->group->contraAsset && $asset->group->assetAccount) {
                    if ($didChange || $force || $existing) {
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
