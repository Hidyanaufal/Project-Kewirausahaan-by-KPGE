<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\OpeningBalanceNameImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportOpeningBalanceByName extends Command
{
    protected $signature = 'accounting:import-opening-balance-by-name
                            {--company-id= : Company ID}
                            {--year=2026 : Tahun fiscal year saldo awal}
                            {--excel= : Path file Excel (opsional jika ada di mapping)}
                            {--mapping= : Path mapping JSON yang sudah approved}
                            {--execute : Tulis ke DB (tanpa flag ini = dry-run)}';

    protected $description = 'Import saldo awal dari mapping approved (default dry-run; pakai --execute untuk write)';

    public function handle(OpeningBalanceNameImportService $service): int
    {
        $mappingPath = (string) ($this->option('mapping') ?: '');
        if ($mappingPath === '' || ! is_file($mappingPath)) {
            $this->error('--mapping wajib dan harus ada.');

            return self::FAILURE;
        }

        $mapping = $service->loadMappingFile($mappingPath);
        $companyId = (int) ($this->option('company-id') ?: ($mapping['company_id'] ?? 0));
        $year = (int) ($this->option('year') ?: ($mapping['year'] ?? 2026));
        $execute = (bool) $this->option('execute');

        if ($companyId <= 0 || ! Company::query()->whereKey($companyId)->exists()) {
            $this->error('company-id tidak valid.');

            return self::FAILURE;
        }

        $mapping['company_id'] = $companyId;
        $mapping['year'] = $year;

        $this->info($execute
            ? "IMPORT LIVE — company {$companyId}, FY {$year}"
            : "DRY-RUN — company {$companyId}, FY {$year} (tambah --execute untuk write)");
        $this->newLine();

        try {
            $planRows = $service->importApprovedMapping($mapping, $execute);
        } catch (Throwable $e) {
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Row', 'Excel Name', 'Action', 'Detail', 'Diff'],
            $planRows
        );

        $this->newLine();
        $this->info($execute ? 'Selesai (DB diubah).' : 'Dry-run selesai (DB tidak diubah).');

        return self::SUCCESS;
    }
}
