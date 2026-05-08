<?php

namespace App\Console\Commands;

use App\Actions\Addons\GenerateYearlyForAsset;
use App\Models\Addons\Asset;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AssetDepreciationRegenerateYear extends Command
{
    protected $signature = 'addons:asset-depr:regenerate-year
        {--company-id= : Company ID (wajib, untuk Company scope)}
        {--fiscal-year= : Tahun fiskal yang akan di-regenerate (mode manual)}
        {--auto : Regenerate otomatis dari tahun mulai depresiasi tiap asset sampai target-year}
        {--target-year= : Tahun terakhir untuk mode --auto (default: tahun sekarang)}
        {--asset-id= : Optional, hanya 1 asset id}
        {--commit : Jalankan perubahan (tanpa ini, hanya dry-run)}';

    protected $description = 'Regenerate DepreciationRecord + jurnal depresiasi untuk 1 tahun fiskal, khusus asset yang start year depresiasinya sama dengan fiscal-year.';

    public function handle(): int
    {
        $companyId = $this->option('company-id') !== null ? (int) $this->option('company-id') : null;
        $fiscalYear = $this->option('fiscal-year') !== null ? (int) $this->option('fiscal-year') : null;
        $auto = (bool) $this->option('auto');
        $targetYear = $this->option('target-year') !== null ? (int) $this->option('target-year') : (int) now()->format('Y');
        $assetId = $this->option('asset-id') !== null ? (int) $this->option('asset-id') : null;
        $commit = (bool) $this->option('commit');

        if (! $companyId) {
            $this->error('--company-id wajib.');
            return self::INVALID;
        }

        if (! $auto && ! $fiscalYear) {
            $this->error('Pilih salah satu: --fiscal-year=YYYY (manual) atau --auto (otomatis).');
            return self::INVALID;
        }

        if (! $commit) {
            $this->warn('DRY-RUN: tidak ada perubahan tersimpan. Tambahkan --commit untuk menjalankan.');
        }

        // Untuk konsistensi scope yang mungkin dipakai beberapa model/concern.
        session(['current_company_id' => $companyId]);

        $query = Asset::query()
            ->where('company_id', $companyId)
            ->when($assetId, fn (Builder $q) => $q->where('id', $assetId))
            ->with(['group']);

        $assets = $query->get();

        if ($assets->isEmpty()) {
            $this->info('Tidak ada asset yang cocok dengan filter.');
            return self::SUCCESS;
        }

        $this->line("Company: {$companyId}");
        if ($auto) {
            $this->line('Mode: auto');
            $this->line("Target year: {$targetYear}");
        } else {
            $this->line('Mode: manual');
            $this->line("Fiscal year: {$fiscalYear}");
        }
        $this->line('Assets scanned: ' . $assets->count());

        $processed = 0;
        $totalCreated = 0;
        $totalSkippedYears = 0;

        $run = function () use ($assets, $auto, $targetYear, $fiscalYear, $commit, &$processed, &$totalCreated, &$totalSkippedYears): void {
            $eligibleCount = 0;

            foreach ($assets as $asset) {
                $acq = $asset->acquisition_date instanceof Carbon
                    ? $asset->acquisition_date->copy()
                    : Carbon::parse($asset->acquisition_date);

                $deprStart = $acq->copy()->startOfMonth();
                if ((int) $acq->format('d') > 15) {
                    $deprStart = $deprStart->addMonth();
                }

                $startYear = (int) $deprStart->format('Y');

                if (! $auto) {
                    if ($startYear !== (int) $fiscalYear) {
                        continue;
                    }
                } else {
                    if ($startYear > $targetYear) {
                        continue;
                    }
                }

                $eligibleCount++;
                $processed++;

                if (! $commit) {
                    if ($auto) {
                        $this->line("DRY-RUN regenerate(auto): asset #{$asset->id} {$asset->name} startYear={$startYear} -> targetYear={$targetYear}");
                    } else {
                        $this->line("DRY-RUN regenerate: asset #{$asset->id} {$asset->name}");
                    }
                    continue;
                }

                $runToYear = $auto ? $targetYear : $fiscalYear;
                $result = GenerateYearlyForAsset::run(asset: $asset, targetYear: (int) $runToYear, force: true);
                $created = count($result['created'] ?? []);
                $skipped = count($result['skipped'] ?? []);

                $totalCreated += $created;
                $totalSkippedYears += $skipped;

                $this->line("OK asset #{$asset->id}: created_records={$created} skipped_years={$skipped}");
            }

            $this->line('Assets eligible: ' . $eligibleCount);
        };

        if ($commit) {
            DB::transaction(function () use ($run): void {
                $run();
            });
        } else {
            $run();
        }

        $this->info("Selesai. processed_assets={$processed} created_records_total={$totalCreated} skipped_years_total={$totalSkippedYears}");

        return self::SUCCESS;
    }
}

