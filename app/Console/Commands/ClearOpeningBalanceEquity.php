<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\OpeningBalanceNameImportService;
use Illuminate\Console\Command;
use Throwable;

class ClearOpeningBalanceEquity extends Command
{
    protected $signature = 'accounting:clear-opening-balance-equity
                            {--company-id= : Company ID}
                            {--year=2026 : Tahun fiscal year yang saldonya ingin dinolkan}
                            {--to-account-id= : ID akun Retained Earnings (opsional)}
                            {--execute : Tulis ke DB (tanpa flag ini = dry-run)}';

    protected $description = 'Nolkan Opening Balance Equity ke Retained Earnings agar saldo awal FY bersih';

    public function handle(OpeningBalanceNameImportService $service): int
    {
        $companyId = (int) ($this->option('company-id') ?: 0);
        $year = (int) ($this->option('year') ?: 2026);
        $execute = (bool) $this->option('execute');
        $toAccountId = $this->option('to-account-id') !== null && $this->option('to-account-id') !== ''
            ? (int) $this->option('to-account-id')
            : null;

        if ($companyId <= 0 || ! Company::query()->whereKey($companyId)->exists()) {
            $this->error('--company-id wajib dan harus valid.');

            return self::FAILURE;
        }

        $this->info($execute
            ? "CLEAR OBE LIVE — company {$companyId}, FY {$year}"
            : "DRY-RUN — company {$companyId}, FY {$year} (tambah --execute untuk write)");
        $this->newLine();

        try {
            $result = $service->clearOpeningBalanceEquity($companyId, $year, $execute, $toAccountId);
        } catch (Throwable $e) {
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $fmt = static fn (int $minor): string => number_format($minor / 100, 2, ',', '.');

        $this->table(
            ['Field', 'Value'],
            [
                ['OBE current (starting FY)', $fmt($result['current_minor'])],
                ['Diff ke 0', $fmt($result['diff'])],
                ['Lawankan ke', $result['retained_earnings_code'].' '.$result['retained_earnings_name']],
                ['Posted at', $result['posted_at']],
                ['Already clear', $result['already_clear'] ? 'yes' : 'no'],
                ['Posted', $result['posted'] ? 'yes' : 'no'],
                ['Transaction ID', $result['transaction_id'] ?? '-'],
            ]
        );

        if ($result['already_clear']) {
            $this->info('OBE sudah 0 — tidak ada jurnal.');
        } elseif (! $execute) {
            $this->warn('Dry-run saja. Jalankan ulang dengan --execute untuk menulis jurnal.');
        } else {
            $this->info('OBE dinolkan. Starting balance FY '.$year.' untuk OBE sekarang 0.');
        }

        return self::SUCCESS;
    }
}
