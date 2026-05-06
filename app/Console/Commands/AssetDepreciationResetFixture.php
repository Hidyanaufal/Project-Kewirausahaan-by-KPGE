<?php

namespace App\Console\Commands;

use App\Actions\Addons\GenerateYearlyForAsset;
use App\Models\Addons\Asset;
use App\Models\Addons\DepreciationRecord;
use App\Models\Accounting\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssetDepreciationResetFixture extends Command
{
    protected $signature = 'addons:asset-depr:fixture
        {assetId : ID asset}
        {--company-id= : Company ID (wajib, untuk CurrentCompanyScope)}
        {--reset-all : Hapus semua DepreciationRecord & jurnal depresiasi asset ini (semua tahun)}
        {--reset-year= : Hapus DepreciationRecord & jurnal depresiasi hanya untuk tahun tertentu}
        {--fixture-year= : Tahun yang akan dibuat sebagai \"data lama\" (default: tahun lalu)}
        {--cutoff-month=10 : Bulan terakhir yang disimpan di fixture (1-12)}
        {--target-year= : Tahun target untuk menjalankan generate setelah fixture (default: tahun sekarang)}
        {--no-generate : Jangan jalankan generate setelah bikin fixture}
        {--commit : Jalankan perubahan (tanpa ini, hanya dry-run)}
    ';

    protected $description = 'Reset depresiasi asset + buat fixture data lama untuk test backfill/lanjut depresiasi.';

    public function handle(): int
    {
        $assetId = (int) $this->argument('assetId');
        $companyId = $this->option('company-id') !== null ? (int) $this->option('company-id') : null;
        $commit = (bool) $this->option('commit');

        if (! $companyId) {
            $this->error('--company-id wajib (dipakai untuk CurrentCompanyScope di CLI).');
            return self::INVALID;
        }

        session(['current_company_id' => $companyId]);

        $resetAll = (bool) $this->option('reset-all');
        $resetYear = $this->option('reset-year') !== null ? (int) $this->option('reset-year') : null;

        $fixtureYear = $this->option('fixture-year') !== null ? (int) $this->option('fixture-year') : (int) now()->subYear()->format('Y');
        $cutoffMonth = (int) $this->option('cutoff-month');
        $targetYear = $this->option('target-year') !== null ? (int) $this->option('target-year') : (int) now()->format('Y');
        $noGenerate = (bool) $this->option('no-generate');

        if ($cutoffMonth < 1 || $cutoffMonth > 12) {
            $this->error('--cutoff-month harus 1..12');
            return self::INVALID;
        }

        if ($resetAll && $resetYear) {
            $this->error('Pilih salah satu: --reset-all atau --reset-year=YYYY');
            return self::INVALID;
        }

        $asset = Asset::with(['group.assetAccount', 'group.contraAsset'])->find($assetId);
        if (! $asset) {
            $this->error("Asset id={$assetId} tidak ditemukan (company scope id={$companyId}).");
            return self::FAILURE;
        }

        if (! $commit) {
            $this->warn('DRY-RUN: tidak ada perubahan tersimpan. Tambahkan --commit untuk menjalankan.');
        }

        $this->line("Asset: #{$asset->id} {$asset->name}");
        $this->line("Company: {$companyId}");

        $run = function () use ($asset, $resetAll, $resetYear, $fixtureYear, $cutoffMonth, $targetYear, $noGenerate, $commit): void {
            $yearsToReset = [];
            if ($resetAll) {
                $yearsToReset = DepreciationRecord::where('asset_id', $asset->id)->pluck('fiscal_year')->all();
            } elseif ($resetYear) {
                $yearsToReset = [$resetYear];
            }

            if (! empty($yearsToReset)) {
                $yearsToReset = array_values(array_unique(array_map('intval', $yearsToReset)));
                sort($yearsToReset);

                $this->info('Reset DepreciationRecord & jurnal untuk tahun: ' . implode(', ', $yearsToReset));

                foreach ($yearsToReset as $year) {
                    $this->deleteDepreciationJournals(assetId: $asset->id, fiscalYear: $year, commit: $commit);
                    if ($commit) {
                        DepreciationRecord::where('asset_id', $asset->id)
                            ->where('fiscal_year', $year)
                            ->delete();
                    }
                }
            }

            if (! $commit) {
                $this->line("DRY-RUN: akan membuat fixture fiscal_year={$fixtureYear} cutoff_month={$cutoffMonth} dan (opsional) generate sampai targetYear={$targetYear}.");
                return;
            }

            // Buat fixture \"data lama\" (tahun tertentu, hanya sampai cutoffMonth).
            $this->info("Membuat fixture: fiscal_year={$fixtureYear}, cutoff_month={$cutoffMonth}");

            GenerateYearlyForAsset::run(asset: $asset, targetYear: $fixtureYear, force: true);

            $record = DepreciationRecord::where('asset_id', $asset->id)
                ->where('fiscal_year', $fixtureYear)
                ->first();

            if (! $record) {
                throw new \RuntimeException("Gagal membuat DepreciationRecord tahun {$fixtureYear}.");
            }

            $monthly = (array) ($record->monthly_breakdown ?? []);
            ksort($monthly);
            $truncated = [];
            foreach ($monthly as $m => $amt) {
                $m = (int) $m;
                if ($m >= 1 && $m <= $cutoffMonth) {
                    $truncated[$m] = (float) $amt;
                }
            }
            ksort($truncated);

            $newExpense = (float) array_sum($truncated);
            $newClosing = max($asset->salvage_value, round(((float) $record->opening_fiscal_book_value) - $newExpense, 2));

            $this->line('Fixture months: ' . implode(',', array_keys($truncated)));

            $record->monthly_breakdown = $truncated;
            $record->depreciation_expense = $newExpense;
            $record->closing_fiscal_book_value = $newClosing;
            $record->save();

            // Hapus jurnal bulan setelah cutoff, biar state fixture realistis (cuma sampai cutoffMonth).
            $this->deleteDepreciationJournalsAfterMonth(
                assetId: $asset->id,
                fiscalYear: $fixtureYear,
                cutoffMonth: $cutoffMonth,
                commit: $commit
            );

            if (! $noGenerate) {
                $this->info("Menjalankan generate (targetYear={$targetYear}, force=false) untuk test backfill/lanjut depresiasi.");
                GenerateYearlyForAsset::run(asset: $asset, targetYear: $targetYear, force: false);

                $updated = DepreciationRecord::where('asset_id', $asset->id)
                    ->where('fiscal_year', $fixtureYear)
                    ->first();

                $updatedMonthly = (array) ($updated?->monthly_breakdown ?? []);
                $has11 = array_key_exists(11, $updatedMonthly);
                $has12 = array_key_exists(12, $updatedMonthly);
                $this->line("Backfill check: has11=" . ($has11 ? '1' : '0') . " has12=" . ($has12 ? '1' : '0'));
            }
        };

        if ($commit) {
            DB::transaction(function () use ($run): void {
                $run();
            });
        } else {
            // tetap jalankan flow untuk output yang sama, tapi tanpa write.
            $run();
        }

        $this->info('Selesai.');
        return self::SUCCESS;
    }

    private function deleteDepreciationJournals(int $assetId, int $fiscalYear, bool $commit): void
    {
        $query = Transaction::withTrashed()
            ->where('type', 'journal')
            ->where('meta->addon', 'asset_depreciation')
            ->where('meta->asset_id', $assetId)
            ->where('meta->fiscal_year', $fiscalYear);

        $count = (int) $query->count();
        $this->line("Jurnal {$fiscalYear}: {$count} transaksi");

        if (! $commit || $count === 0) {
            return;
        }

        $query->with('journalEntries')->get()->each(function (Transaction $transaction): void {
            $transaction->journalEntries()->delete();
            $transaction->forceDelete();
        });
    }

    private function deleteDepreciationJournalsAfterMonth(int $assetId, int $fiscalYear, int $cutoffMonth, bool $commit): void
    {
        $query = Transaction::withTrashed()
            ->where('type', 'journal')
            ->where('meta->addon', 'asset_depreciation')
            ->where('meta->asset_id', $assetId)
            ->where('meta->fiscal_year', $fiscalYear)
            ->where('meta->fiscal_month', '>', $cutoffMonth);

        $count = (int) $query->count();
        $this->line("Hapus jurnal bulan > {$cutoffMonth} pada {$fiscalYear}: {$count} transaksi");

        if (! $commit || $count === 0) {
            return;
        }

        $query->with('journalEntries')->get()->each(function (Transaction $transaction): void {
            $transaction->journalEntries()->delete();
            $transaction->forceDelete();
        });
    }
}

