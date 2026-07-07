<?php

namespace App\Console\Commands;

use App\Imports\LegacyProjectInvoiceWorkbookImport;
use App\Models\Accounting\Invoice;
use App\Models\User;
use App\Services\LegacyProjectInvoiceImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ReimportLegacyInvoices extends Command
{
    protected $signature = 'legacy:reimport-invoices
                            {companyId : Company ID}
                            {--file= : Path to legacy Excel workbook}
                            {--dry-run : Preview affected invoices without importing}
                            {--only-broken : Only reset and re-import corrupted imported invoices}
                            {--prefer-supplement : Prioritaskan sheet supplement (t) untuk angka finansial}
                            {--actor=1 : User ID used as created_by / updated_by during import}';

    protected $description = 'Reset and re-import legacy project & invoice data from Excel workbook';

    public function handle(): int
    {
        $companyId = (int) $this->argument('companyId');
        $filePath = $this->resolveFilePath();
        $dryRun = (bool) $this->option('dry-run');
        $onlyBroken = (bool) $this->option('only-broken');
        $preferSupplement = (bool) $this->option('prefer-supplement');
        $actorId = (int) $this->option('actor');

        if (! User::query()->whereKey($actorId)->exists()) {
            $this->error("User ID {$actorId} tidak ditemukan.");

            return self::FAILURE;
        }

        $query = Invoice::query()
            ->where('company_id', $companyId)
            ->where('is_imported', true);

        if ($onlyBroken) {
            $query->where(function ($subQuery): void {
                $subQuery
                    ->where('total', 0)
                    ->orWhere('total', '>', 1_000_000_000)
                    ->orWhere(function ($paidQuery): void {
                        $paidQuery
                            ->where('status', 'paid')
                            ->whereDoesntHave('payments');
                    });
            });
        }

        $invoices = $query->orderBy('id')->get();
        $sourceLabel = $preferSupplement ? 'supplement (t)' : 'sheet Invoice';

        $this->info(sprintf(
            'Mode: %s | Scope: %s | Sumber angka: %s | Invoices to reset: %d',
            $dryRun ? 'DRY-RUN' : 'LIVE',
            $onlyBroken ? 'only-broken' : 'all imported',
            $sourceLabel,
            $invoices->count(),
        ));

        if ($invoices->isEmpty()) {
            $this->warn('Tidak ada invoice imported yang perlu di-reset.');

            if ($dryRun) {
                return self::SUCCESS;
            }
        }

        $importer = new LegacyProjectInvoiceWorkbookImport(
            companyId: $companyId,
            actorId: $actorId,
            preferSupplementFinancials: $preferSupplement,
        );

        $workbookInvoices = $importer->previewMappedInvoices($filePath);

        if ($dryRun) {
            $previewRows = $invoices->map(function (Invoice $invoice) use ($workbookInvoices) {
                $mapped = $workbookInvoices[$invoice->invoice_number] ?? null;
                $excelTotal = $mapped['total'] ?? null;

                return [
                    $invoice->id,
                    $invoice->invoice_number,
                    number_format($invoice->total / 100, 0, ',', '.'),
                    $excelTotal !== null ? number_format($excelTotal / 100, 0, ',', '.') : '-',
                    $excelTotal !== null && (int) $invoice->total === (int) $excelTotal ? 'cocok' : 'beda',
                ];
            });

            $mismatchCount = $previewRows->filter(fn (array $row) => $row[4] === 'beda')->count();

            $this->table(
                ['ID', 'Invoice', 'Total DB', 'Total Excel', 'Status'],
                $previewRows->take(25)->all(),
            );

            if ($previewRows->count() > 25) {
                $this->line(sprintf('... dan %d invoice lainnya.', $previewRows->count() - 25));
            }

            $this->newLine();
            $this->info(sprintf(
                'Preview: %d invoice akan di-reset, %d total beda dengan sheet Invoice Excel.',
                $invoices->count(),
                $mismatchCount,
            ));

            if ($mismatchCount > 0) {
                $this->line('Jalankan tanpa --dry-run untuk re-import dari Excel.');
            }

            $this->warn('DRY-RUN selesai. Tidak ada perubahan database.');

            return self::SUCCESS;
        }

        $previousCompanyId = session('current_company_id');
        session(['current_company_id' => $companyId]);

        try {
            $service = new LegacyProjectInvoiceImportService($companyId, $actorId);
            $service->setPreferSupplementFinancials($preferSupplement);

            $this->withProgressBar($invoices, function (Invoice $invoice) use ($service): void {
                $service->prepareImportedInvoiceForReimport($invoice);
            });

            $this->newLine(2);
            $this->info('Reset selesai. Memulai import workbook...');

            $importer = new LegacyProjectInvoiceWorkbookImport(
                companyId: $companyId,
                actorId: $actorId,
                forceReimport: true,
                preferSupplementFinancials: $preferSupplement,
            );

            $importer->importFile($filePath);

            $this->newLine();
            $this->line($importer->notificationBody());
        } finally {
            if ($previousCompanyId) {
                session(['current_company_id' => $previousCompanyId]);
            } else {
                session()->forget('current_company_id');
            }
        }

        return self::SUCCESS;
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
}
