<?php

namespace App\Imports;

use App\Services\LegacyProjectInvoiceImportService;
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
    ) {}

    public function importFile(string $filePath): void
    {
        $spreadsheet = IOFactory::load($filePath);
        $service = new LegacyProjectInvoiceImportService($this->companyId, $this->actorId);

        if ($sheet = $spreadsheet->getSheetByName('t')) {
            $service->rememberInvoiceSupplementRows($this->extractRows($sheet));
        }

        foreach (['Project-Pengadaan', 'Project-EO'] as $sheetName) {
            $sheet = $spreadsheet->getSheetByName($sheetName);

            if (! $sheet) {
                $this->missingSheetWarnings[] = "Sheet '{$sheetName}' tidak ditemukan di workbook.";

                continue;
            }

            $service->importProjectSheet($sheetName, $this->extractRows($sheet));
        }

        if ($sheet = $spreadsheet->getSheetByName('Invoice')) {
            $service->importInvoiceSheet($this->extractRows($sheet));
        } else {
            $this->missingSheetWarnings[] = "Sheet 'Invoice' tidak ditemukan di workbook.";
        }

        $this->summary = $service->summary();
        $this->summary['warnings'] = array_merge(
            $this->summary['warnings'] ?? [],
            $this->missingSheetWarnings,
        );
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
                'Projects: %d inserted, %d updated, %d skipped, %d failed.',
                $projects['inserted'],
                $projects['updated'],
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
