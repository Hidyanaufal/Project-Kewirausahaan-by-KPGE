<?php

namespace App\Imports;

use App\Services\LegacyProjectInvoiceImportService;
use App\Services\LegacyWorkbookLayout;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LegacyProjectInvoiceWorkbookImport
{
    protected array $summary = [];

    protected array $missingSheetWarnings = [];

    public function __construct(
        protected int $companyId,
        protected int $actorId,
        protected bool $forceReimport = false,
    ) {}

    public function importFile(string $filePath): void
    {
        $previousCompanyId = session('current_company_id');
        session(['current_company_id' => $this->companyId]);

        try {
            $spreadsheet = IOFactory::load($filePath);
            $service = (new LegacyProjectInvoiceImportService($this->companyId, $this->actorId))
                ->setForceReimport($this->forceReimport);

            $invoiceSheet = $spreadsheet->getSheetByName('Invoice');
            $supplementSheet = $spreadsheet->getSheetByName('t');

            $invoiceHeader = $invoiceSheet ? $this->extractHeaderRow($invoiceSheet) : [];
            $invoiceSampleRows = $invoiceSheet ? array_slice($this->extractRows($invoiceSheet), 0, 5) : [];
            $supplementHeader = $supplementSheet ? $this->extractHeaderRow($supplementSheet) : [];

            $layout = LegacyWorkbookLayout::detect($invoiceHeader, $invoiceSampleRows, $supplementHeader);
            $service->setWorkbookLayout($layout);

            if ($supplementSheet) {
                $service->rememberInvoiceSupplementRows($this->extractRows($supplementSheet));
            }

            $projectSheets = collect($spreadsheet->getSheetNames())
                ->filter(fn (string $name) => str_starts_with($name, 'Project-'))
                ->values()
                ->all();

            if ($projectSheets === []) {
                $this->missingSheetWarnings[] = "Tidak ada sheet 'Project-*' ditemukan di workbook.";
            }

            foreach ($projectSheets as $sheetName) {
                $sheet = $spreadsheet->getSheetByName($sheetName);
                $service->importProjectSheet($sheetName, $this->extractRows($sheet));
            }

            if ($invoiceSheet) {
                $service->importInvoiceSheet($this->extractRows($invoiceSheet));
            } else {
                $this->missingSheetWarnings[] = "Sheet 'Invoice' tidak ditemukan di workbook.";
            }

            $this->summary = $service->summary();
            $this->summary['warnings'] = array_merge(
                $this->summary['warnings'] ?? [],
                $this->missingSheetWarnings,
            );
        } finally {
            if ($previousCompanyId) {
                session(['current_company_id' => $previousCompanyId]);
            } else {
                session()->forget('current_company_id');
            }
        }
    }

    public function notificationTitle(): string
    {
        $failed = ($this->summary['projects']['failed'] ?? 0) + ($this->summary['invoices']['failed'] ?? 0);

        return $failed > 0
            ? 'Legacy Import Completed With Warnings'
            : 'Legacy Import Completed';
    }

    public function notificationBody(): string
    {
        $projects = $this->summary['projects'] ?? ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $invoices = $this->summary['invoices'] ?? ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $warnings = $this->summary['warnings'] ?? [];

        $lines = [
            sprintf(
                'Projects: %d inserted, %d updated, %d stubbed, %d skipped, %d failed.',
                $projects['inserted'],
                $projects['updated'],
                $projects['stubbed'] ?? 0,
                $projects['skipped'],
                $projects['failed'],
            ),
            sprintf(
                'Invoices: %d inserted, %d updated, %d skipped, %d failed.',
                $invoices['inserted'],
                $invoices['updated'],
                $invoices['skipped'],
                $invoices['failed'],
            ),
            sprintf(
                'Invoice validation: %d with total > 0, %d PAID with payment, %d rejected (total 0).',
                $invoices['with_total'] ?? 0,
                $invoices['paid_with_payment'] ?? 0,
                $invoices['zero_total_rejected'] ?? 0,
            ),
        ];

        if ($warnings !== []) {
            $preview = array_slice($warnings, 0, 5);
            $lines[] = 'Warnings: ' . implode(' | ', $preview);

            if (count($warnings) > count($preview)) {
                $lines[] = sprintf('...and %d more warnings.', count($warnings) - count($preview));
            }
        }

        return implode("\n", $lines);
    }

    public function notificationStatus(): string
    {
        $failed = ($this->summary['projects']['failed'] ?? 0) + ($this->summary['invoices']['failed'] ?? 0);
        $warnings = count($this->summary['warnings'] ?? []);

        if ($failed > 0 || $warnings > 0) {
            return 'warning';
        }

        return 'success';
    }

    protected function extractHeaderRow(Worksheet $worksheet): array
    {
        $highestColumnIndex = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());
        $header = [];

        for ($columnIndex = 1; $columnIndex <= $highestColumnIndex; $columnIndex++) {
            $header[] = $worksheet->getCell([$columnIndex, 1])->getFormattedValue();
        }

        return $header;
    }

    protected function extractRows(Worksheet $worksheet): array
    {
        $highestRow = $worksheet->getHighestDataRow();
        $highestColumnIndex = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());
        $rows = [];

        for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
            $values = [];

            for ($columnIndex = 1; $columnIndex <= $highestColumnIndex; $columnIndex++) {
                $values[] = $worksheet->getCell([$columnIndex, $rowNumber])->getFormattedValue();
            }

            $rows[] = [
                'row_number' => $rowNumber,
                'values' => $values,
            ];
        }

        return $rows;
    }
}
