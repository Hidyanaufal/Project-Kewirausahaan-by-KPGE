<?php

namespace App\Console\Commands;

use App\Actions\Addons\GenerateYearlyForAsset;
use App\Models\Addons\Asset;
use App\Models\Addons\AssetGroup;
use App\Models\Addons\DepreciationRecord;
use App\Models\Accounting\Transaction;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AssetDepreciationScenarioCommand extends Command
{
    protected $signature = 'addons:asset-depr:scenario
        {--company-id= : Company ID (wajib, untuk CurrentCompanyScope)}
        {--group-id= : Asset group id (opsional; default: group pertama yg punya asset_account_id & contra_account_id)}
        {--acq-date=2024-01-10 : Acquisition date untuk asset test (YYYY-MM-DD)}
        {--acq-cost=1200000000 : Acquisition cost (dalam cents) untuk asset test}
        {--salvage=100000000 : Salvage value (dalam cents)}
        {--life-months=120 : Useful life months (override di asset, biar cukup panjang sampai 2026)}
        {--first-as-of=2025-08-31 : Tanggal \"sekarang\" saat generate pertama (YYYY-MM-DD)}
        {--first-target-year=2025 : targetYear untuk generate pertama}
        {--cutoff-month=8 : Setelah generate pertama, potong record tahun first-target-year sampai bulan ini (1-12) untuk simulasi data lama}
        {--stop-after-phase1 : Hanya buat data sampai phase1 (tanpa menjalankan phase2)}
        {--second-as-of= : Tanggal \"sekarang\" saat generate kedua (YYYY-MM-DD). Default: today}
        {--second-target-year=2026 : targetYear untuk generate kedua}
        {--commit : Jalankan perubahan (tanpa ini, hanya dry-run)}
    ';

    protected $description = 'Buat skenario asset 2024, generate (as-of Aug 2025), potong sampai cutoff, lalu generate lagi (as-of 2026) untuk test backfill bulan sisa tahun 2025.';

    public function handle(): int
    {
        $companyId = $this->option('company-id') !== null ? (int) $this->option('company-id') : null;
        $commit = (bool) $this->option('commit');

        if (! $companyId) {
            $this->error('--company-id wajib.');
            return self::INVALID;
        }

        session(['current_company_id' => $companyId]);

        $groupId = $this->option('group-id') !== null ? (int) $this->option('group-id') : null;
        $group = $groupId
            ? AssetGroup::with(['assetAccount', 'contraAsset'])->find($groupId)
            : AssetGroup::with(['assetAccount', 'contraAsset'])
                ->whereNotNull('asset_account_id')
                ->whereNotNull('contra_account_id')
                ->first();

        if (! $group || ! $group->assetAccount || ! $group->contraAsset) {
            $this->error('AssetGroup tidak ditemukan / belum punya assetAccount & contraAsset.');
            return self::FAILURE;
        }

        $acqDate = Carbon::parse((string) $this->option('acq-date'))->startOfDay();
        $acqCost = (int) $this->option('acq-cost');
        $salvage = (int) $this->option('salvage');
        $lifeMonths = (int) $this->option('life-months');

        $firstAsOf = Carbon::parse((string) $this->option('first-as-of'))->endOfDay();
        $firstTargetYear = (int) $this->option('first-target-year');
        $cutoffMonth = (int) $this->option('cutoff-month');
        $stopAfterPhase1 = (bool) $this->option('stop-after-phase1');

        $secondAsOf = $this->option('second-as-of') ? Carbon::parse((string) $this->option('second-as-of'))->endOfDay() : now()->endOfDay();
        $secondTargetYear = (int) $this->option('second-target-year');

        if ($cutoffMonth < 1 || $cutoffMonth > 12) {
            $this->error('--cutoff-month harus 1..12');
            return self::INVALID;
        }

        if (! $commit) {
            $this->warn('DRY-RUN: tidak ada perubahan tersimpan. Tambahkan --commit untuk menjalankan.');
        }

        $this->line("Company: {$companyId}");
        $this->line("Group: #{$group->id} {$group->name}");
        $this->line("Acq: {$acqDate->toDateString()} cost={$acqCost} salvage={$salvage} life={$lifeMonths}m");
        $this->line("Phase1: asOf={$firstAsOf->toDateString()} targetYear={$firstTargetYear} cutoffMonth={$cutoffMonth}");
        if (! $stopAfterPhase1) {
            $this->line("Phase2: asOf={$secondAsOf->toDateString()} targetYear={$secondTargetYear}");
        } else {
            $this->line('Phase2: (skip)');
        }

        $run = function () use (
            $group,
            $acqDate,
            $acqCost,
            $salvage,
            $lifeMonths,
            $firstAsOf,
            $firstTargetYear,
            $cutoffMonth,
            $secondAsOf,
            $secondTargetYear,
            $stopAfterPhase1,
            $commit
        ): void {
            Carbon::setTestNow($acqDate);

            $fixtureId = (string) Str::ulid();
            $assetName = 'Fixture Asset Depr ' . $fixtureId;
            $asset = new Asset([
                'group_id' => $group->id,
                'asset_code' => 'FIX-DEPR-' . $fixtureId,
                'name' => $assetName,
                'acquisition_date' => $acqDate->copy(),
                'acquisition_cost' => $acqCost,
                'salvage_value' => $salvage,
                'useful_life_months' => $lifeMonths,
                'status' => 'active',
                'meta' => [
                    'fixture' => 'asset_depr_scenario',
                ],
            ]);

            if ($commit) {
                $asset->save();
            }

            $this->info('Asset fixture dibuat: ' . ($commit ? "#{$asset->id} {$asset->name}" : '(dry-run)'));

            // Phase 1: generate as-of Aug 2025
            Carbon::setTestNow($firstAsOf);
            $this->info("Phase1 generate: now={$firstAsOf->toDateString()} targetYear={$firstTargetYear}");
            if ($commit) {
                GenerateYearlyForAsset::run(asset: $asset, targetYear: $firstTargetYear, force: false);
            }

            // Potong record tahun firstTargetYear sampai cutoffMonth
            if ($commit) {
                $record = DepreciationRecord::where('asset_id', $asset->id)
                    ->where('fiscal_year', $firstTargetYear)
                    ->first();

                if (! $record) {
                    throw new \RuntimeException("DepreciationRecord {$firstTargetYear} tidak terbentuk pada phase1.");
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

                $record->monthly_breakdown = $truncated;
                $record->depreciation_expense = (float) array_sum($truncated);
                $record->closing_fiscal_book_value = max($asset->salvage_value, round(((float) $record->opening_fiscal_book_value) - ((float) $record->depreciation_expense), 2));
                $record->save();

                // Hapus jurnal bulan setelah cutoffMonth untuk tahun tersebut (biar state lama konsisten)
                Transaction::withTrashed()
                    ->where('type', 'journal')
                    ->where('meta->addon', 'asset_depreciation')
                    ->where('meta->asset_id', $asset->id)
                    ->where('meta->fiscal_year', $firstTargetYear)
                    ->where('meta->fiscal_month', '>', $cutoffMonth)
                    ->with('journalEntries')
                    ->get()
                    ->each(function (Transaction $t): void {
                        $t->journalEntries()->delete();
                        $t->forceDelete();
                    });

                $this->info("Record {$firstTargetYear} dipotong sampai bulan {$cutoffMonth}.");
            } else {
                $this->line("DRY-RUN: akan memotong DepreciationRecord {$firstTargetYear} sampai bulan {$cutoffMonth}.");
            }

            if ($stopAfterPhase1) {
                if ($commit) {
                    $this->info('Stop after phase1. Asset fixture id=' . $asset->id);
                }
                Carbon::setTestNow();
                return;
            }

            // Phase 2: generate as-of 2026 (bulan ini)
            Carbon::setTestNow($secondAsOf);
            $this->info("Phase2 generate: now={$secondAsOf->toDateString()} targetYear={$secondTargetYear}");
            if ($commit) {
                GenerateYearlyForAsset::run(asset: $asset, targetYear: $secondTargetYear, force: false);
            }

            // Output hasil: apakah 2025 bulan 11/12 ada + jurnalnya ada
            if ($commit) {
                $updated = DepreciationRecord::where('asset_id', $asset->id)
                    ->where('fiscal_year', $firstTargetYear)
                    ->first();

                $keys = array_keys((array) ($updated?->monthly_breakdown ?? []));
                sort($keys);

                $this->line("Months in {$firstTargetYear}: " . implode(',', $keys));
                $has11 = in_array(11, $keys, true);
                $has12 = in_array(12, $keys, true);
                $this->line("Backfill check {$firstTargetYear}: has11=" . ($has11 ? '1' : '0') . " has12=" . ($has12 ? '1' : '0'));

                $tx11 = Transaction::withTrashed()
                    ->where('type', 'journal')
                    ->where('meta->addon', 'asset_depreciation')
                    ->where('meta->asset_id', $asset->id)
                    ->where('meta->fiscal_year', $firstTargetYear)
                    ->where('meta->fiscal_month', 11)
                    ->count();
                $tx12 = Transaction::withTrashed()
                    ->where('type', 'journal')
                    ->where('meta->addon', 'asset_depreciation')
                    ->where('meta->asset_id', $asset->id)
                    ->where('meta->fiscal_year', $firstTargetYear)
                    ->where('meta->fiscal_month', 12)
                    ->count();

                $this->line("Journal tx {$firstTargetYear}-11={$tx11} {$firstTargetYear}-12={$tx12}");
                $this->info('Skenario selesai. Asset fixture id=' . $asset->id);
            }

            Carbon::setTestNow();
        };

        if ($commit) {
            DB::transaction(function () use ($run): void {
                $run();
            });
        } else {
            $run();
        }

        return self::SUCCESS;
    }
}

