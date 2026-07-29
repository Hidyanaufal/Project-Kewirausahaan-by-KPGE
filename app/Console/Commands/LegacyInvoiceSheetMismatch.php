<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LegacyInvoiceSheetMismatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class LegacyInvoiceSheetMismatch extends Command
{
    protected $signature = 'legacy:invoice-sheet-mismatch
                            {action : audit|fix-excel|fix-db}
                            {--file= : Path to legacy Excel workbook}
                            {--company= : Company ID (wajib untuk fix-db dan audit status database)}
                            {--output= : Output workbook path for fix-excel (default: overwrite dengan suffix -fixed)}
                            {--actor=1 : User ID untuk fix-db}
                            {--dry-run : Preview tanpa mengubah file atau database}';

    protected $description = 'Audit dan perbaiki selisih data finansial sheet Invoice vs supplement (t) pada workbook legacy';

    public function handle(LegacyInvoiceSheetMismatchService $service): int
    {
        $action = strtolower((string) $this->argument('action'));
        $filePath = $this->resolveFilePath();

        return match ($action) {
            'audit' => $this->runAudit($service, $filePath),
            'fix-excel' => $this->runFixExcel($service, $filePath),
            'fix-db' => $this->runFixDatabase($service, $filePath),
            default => $this->invalidAction($action),
        };
    }

    protected function runAudit(LegacyInvoiceSheetMismatchService $service, string $filePath): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $result = $service->audit($filePath, $companyId);
        $summary = $result['summary'];

        $this->info('Ringkasan audit sheet Invoice vs supplement (t):');
        $this->table(
            ['Metrik', 'Jumlah'],
            [
                ['Baris invoice terbaca', $summary['invoice_rows']],
                ['Punya data supplement', $summary['with_supplement']],
                ['Sheet cocok dengan supplement', $summary['sheet_matches_supplement']],
                ['Sheet tidak cocok', $summary['sheet_mismatches']],
                ['Indikasi baris bergeser', $summary['shifted_row_suspects']],
                ['DB cocok supplement', $companyId ? $summary['db_matches_supplement'] : 'n/a'],
                ['DB tidak cocok supplement', $companyId ? $summary['db_mismatches'] : 'n/a'],
                ['Invoice belum ada di DB', $companyId ? $summary['db_missing'] : 'n/a'],
            ],
        );

        if ($result['mismatches'] === []) {
            $this->info('Tidak ada selisih antara sheet Invoice dan supplement.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn(sprintf('Ditemukan %d invoice dengan angka sheet Invoice berbeda dari supplement.', count($result['mismatches'])));

        $this->table(
            ['Baris', 'Invoice', 'Total Sheet', 'Total Supplement', 'Geser Dari', 'Status DB'],
            collect($result['mismatches'])->take(30)->map(fn (array $row) => [
                $row['row_number'],
                $row['invoice_number'],
                $row['sheet']['total'],
                $row['supplement']['total'],
                $row['shifted_from'] ?? '-',
                $row['db_status'],
            ])->all(),
        );

        if (count($result['mismatches']) > 30) {
            $this->line(sprintf('... dan %d invoice lainnya.', count($result['mismatches']) - 30));
        }

        $this->newLine();
        $this->line('Perbaiki Excel: php artisan legacy:invoice-sheet-mismatch fix-excel --file="..."');
        $this->line('Perbaiki DB: php artisan legacy:invoice-sheet-mismatch fix-db --file="..." --company=ID');

        return self::SUCCESS;
    }

    protected function runFixExcel(LegacyInvoiceSheetMismatchService $service, string $filePath): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $outputPath = $this->resolveOutputPath($filePath);

        if ($dryRun) {
            $audit = $service->audit($filePath);
            $this->warn('DRY-RUN: tidak ada file yang diubah.');
            $this->info(sprintf(
                'Akan memperbaiki %d baris di sheet Invoice → %s',
                count($audit['mismatches']),
                $outputPath,
            ));

            return self::SUCCESS;
        }

        $result = $service->fixExcel($filePath, $outputPath, dryRun: false);

        $this->info(sprintf(
            'Berhasil memperbaiki %d baris di sheet Invoice.',
            $result['fixed'],
        ));
        $this->line("File output: {$result['output_path']}");

        return self::SUCCESS;
    }

    protected function runFixDatabase(LegacyInvoiceSheetMismatchService $service, string $filePath): int
    {
        $companyId = $this->option('company');

        if ($companyId === null) {
            $this->error('Option --company wajib diisi untuk fix-db.');

            return self::FAILURE;
        }

        $actorId = (int) $this->option('actor');
        $dryRun = (bool) $this->option('dry-run');

        if (! User::query()->whereKey($actorId)->exists()) {
            $this->error("User ID {$actorId} tidak ditemukan.");

            return self::FAILURE;
        }

        $audit = $service->audit($filePath, (int) $companyId);
        $toFix = collect($audit['mismatches'])
            ->filter(fn (array $row) => $row['db_status'] === 'mismatch')
            ->pluck('invoice_number')
            ->values();

        if ($toFix->isEmpty()) {
            $this->info('Tidak ada invoice di database yang perlu diperbaiki (semua sudah cocok dengan supplement).');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN: tidak ada perubahan database.');
            $this->table(['Invoice Number'], $toFix->map(fn (string $number) => [$number])->all());

            return self::SUCCESS;
        }

        if (! $this->confirm(sprintf('Re-import %d invoice dari supplement?', $toFix->count()), true)) {
            $this->warn('Dibatalkan.');

            return self::SUCCESS;
        }

        $result = $service->fixDatabase($filePath, (int) $companyId, $actorId, dryRun: false);

        $this->info(sprintf('Selesai re-import %d invoice.', $result['fixed']));
        $this->line('Invoice: ' . implode(', ', $result['invoice_numbers']));

        return self::SUCCESS;
    }

    protected function invalidAction(string $action): int
    {
        $this->error("Action '{$action}' tidak dikenal. Gunakan: audit, fix-excel, atau fix-db.");

        return self::FAILURE;
    }

    protected function resolveFilePath(): string
    {
        $filePath = $this->option('file');

        if (! $filePath) {
            $this->error('Option --file wajib diisi dengan path ke workbook Excel legacy.');

            exit(self::FAILURE);
        }

        $resolvedPath = str_starts_with($filePath, DIRECTORY_SEPARATOR)
            ? $filePath
            : base_path($filePath);

        if (! File::exists($resolvedPath)) {
            $this->error("File tidak ditemukan: {$resolvedPath}");

            exit(self::FAILURE);
        }

        return $resolvedPath;
    }

    protected function resolveOutputPath(string $filePath): string
    {
        $output = $this->option('output');

        if ($output) {
            return str_starts_with($output, DIRECTORY_SEPARATOR)
                ? $output
                : base_path($output);
        }

        $pathInfo = pathinfo($filePath);

        return $pathInfo['dirname'] . DIRECTORY_SEPARATOR . $pathInfo['filename'] . '-fixed.' . ($pathInfo['extension'] ?? 'xlsx');
    }
}
