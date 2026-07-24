<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\OpeningBalanceNameImportService;
use Illuminate\Console\Command;
use Throwable;

class BalanceUnbalancedJournalsToObe extends Command
{
    protected $signature = 'accounting:balance-unbalanced-journals-to-obe
                            {--company-id= : Company ID}
                            {--as-of= : Tanggal as-of (Y-m-d), default: sehari sebelum FY start tahun}
                            {--year=2026 : Tahun FY (untuk default as-of & clear OBE)}
                            {--clear-obe : Setelah plug, nolkan OBE ke Retained Earnings}
                            {--execute : Tulis ke DB (tanpa flag = dry-run)}';

    protected $description = 'Tambah kaki lawan ke Opening Balance Equity untuk jurnal D≠C agar Trial Balance balance';

    public function handle(OpeningBalanceNameImportService $service): int
    {
        $companyId = (int) ($this->option('company-id') ?: 0);
        $year = (int) ($this->option('year') ?: 2026);
        $execute = (bool) $this->option('execute');
        $clearObe = (bool) $this->option('clear-obe');

        if ($companyId <= 0 || ! Company::query()->whereKey($companyId)->exists()) {
            $this->error('--company-id wajib dan harus valid.');

            return self::FAILURE;
        }

        $company = Company::query()->findOrFail($companyId);
        $asOf = $this->option('as-of');
        if ($asOf === null || $asOf === '') {
            $asOf = $service->computeFiscalYearStartForYear($company, $year)
                ->copy()
                ->subSecond()
                ->toDateString();
        }

        $this->info($execute
            ? "LIVE — company {$companyId}, as-of {$asOf}"
            : "DRY-RUN — company {$companyId}, as-of {$asOf}");

        try {
            $result = $service->balanceUnbalancedJournalsToOpeningEquity($companyId, $asOf, $execute);
        } catch (Throwable $e) {
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $fmt = static fn (int $minor): string => number_format($minor / 100, 2, ',', '.');

        $this->table(
            ['Field', 'Value'],
            [
                ['Scanned unbalanced', (string) $result['scanned']],
                ['Fixed / would fix', (string) $result['fixed']],
                ['Net debit excess', $fmt($result['net_debit_excess'])],
            ]
        );

        if ($result['details'] !== []) {
            $this->table(
                ['Txn', 'Diff (D-C)', 'Plug type', 'Amount'],
                collect($result['details'])->take(25)->map(fn (array $d) => [
                    $d['transaction_id'],
                    $fmt($d['diff']),
                    $d['entry_type'],
                    $fmt($d['amount']),
                ])->all()
            );
            if (count($result['details']) > 25) {
                $this->line('... dan '.(count($result['details']) - 25).' lainnya');
            }
        }

        if ($clearObe) {
            $clear = $service->clearOpeningBalanceEquity($companyId, $year, $execute);
            $this->newLine();
            $this->info('Clear OBE: already_clear='.($clear['already_clear'] ? 'yes' : 'no')
                .' posted='.($clear['posted'] ? 'yes' : 'no')
                .' diff='.$fmt($clear['diff']));
        }

        if (! $execute) {
            $this->warn('Dry-run saja. Jalankan ulang dengan --execute untuk menulis.');
        }

        return self::SUCCESS;
    }
}
