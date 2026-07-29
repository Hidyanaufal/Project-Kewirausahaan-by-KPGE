<?php

namespace App\Services;

use App\Imports\LegacyProjectInvoiceWorkbookImport;
use App\Models\Accounting\Invoice;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class LegacyInvoiceSheetMismatchService
{
    protected LegacyWorkbookLayout $layout;

    /** @var array<string, array<string, mixed>> */
    protected array $supplementByInvoice = [];

    /** @var array<int, array<string, mixed>> */
    protected array $invoiceSheetRows = [];

    public function audit(string $filePath, ?int $companyId = null): array
    {
        $this->loadWorkbook($filePath);

        $mismatches = [];
        $summary = [
            'invoice_rows' => 0,
            'with_supplement' => 0,
            'sheet_matches_supplement' => 0,
            'sheet_mismatches' => 0,
            'db_matches_supplement' => 0,
            'db_mismatches' => 0,
            'db_missing' => 0,
            'shifted_row_suspects' => 0,
        ];

        $supplementTotalsByInvoice = collect($this->supplementByInvoice)
            ->mapWithKeys(fn (array $row, string $invoiceNumber) => [
                $invoiceNumber => (int) ($row['total'] ?? 0),
            ])
            ->all();

        foreach ($this->invoiceSheetRows as $row) {
            $invoiceNumber = $row['invoice_number'];

            if ($invoiceNumber === null) {
                continue;
            }

            $summary['invoice_rows']++;

            $supplement = $this->supplementByInvoice[$invoiceNumber] ?? null;

            if ($supplement === null) {
                continue;
            }

            $summary['with_supplement']++;

            if ($this->amountsMatch($row, $supplement)) {
                $summary['sheet_matches_supplement']++;

                continue;
            }

            $summary['sheet_mismatches']++;

            $shiftedFrom = $this->detectShiftedSourceInvoice(
                (int) ($row['total'] ?? 0),
                $invoiceNumber,
                $supplementTotalsByInvoice,
            );

            if ($shiftedFrom !== null) {
                $summary['shifted_row_suspects']++;
            }

            $dbInvoice = $companyId
                ? Invoice::query()
                    ->where('company_id', $companyId)
                    ->where('invoice_number', $invoiceNumber)
                    ->first()
                : null;

            $dbStatus = 'n/a';

            if ($companyId === null) {
                $dbStatus = 'skipped';
            } elseif ($dbInvoice === null) {
                $summary['db_missing']++;
                $dbStatus = 'missing';
            } elseif ((int) $dbInvoice->total === (int) ($supplement['total'] ?? 0)) {
                $summary['db_matches_supplement']++;
                $dbStatus = 'ok';
            } else {
                $summary['db_mismatches']++;
                $dbStatus = 'mismatch';
            }

            $mismatches[] = [
                'row_number' => $row['row_number'],
                'invoice_number' => $invoiceNumber,
                'project_reference' => $row['project_reference'],
                'description' => $row['description'],
                'sheet' => $this->formatAmountSnapshot($row),
                'supplement' => $this->formatAmountSnapshot($supplement),
                'shifted_from' => $shiftedFrom,
                'db_status' => $dbStatus,
                'db_total' => $dbInvoice?->total,
            ];
        }

        return [
            'summary' => $summary,
            'mismatches' => $mismatches,
        ];
    }

    /**
     * @return array{fixed: int, output_path: string}
     */
    public function fixExcel(string $filePath, string $outputPath, bool $dryRun = false): array
    {
        $audit = $this->audit($filePath);
        $fixed = 0;

        if ($dryRun || $audit['mismatches'] === []) {
            return [
                'fixed' => 0,
                'output_path' => $outputPath,
                'mismatches' => $audit['mismatches'],
            ];
        }

        if ($filePath !== $outputPath && ! copy($filePath, $outputPath)) {
            throw new \RuntimeException("Gagal menyalin workbook ke {$outputPath}.");
        }

        $targetPath = $filePath === $outputPath ? $filePath : $outputPath;
        $spreadsheet = IOFactory::load($targetPath);
        $invoiceSheet = $spreadsheet->getSheetByName('Invoice');

        if (! $invoiceSheet) {
            throw new \RuntimeException("Sheet 'Invoice' tidak ditemukan di workbook.");
        }

        foreach ($audit['mismatches'] as $mismatch) {
            $supplement = $this->supplementByInvoice[$mismatch['invoice_number']] ?? null;

            if ($supplement === null) {
                continue;
            }

            $this->writeInvoiceSheetAmounts($invoiceSheet, (int) $mismatch['row_number'], $supplement);
            $fixed++;
        }

        (new Xlsx($spreadsheet))->save($targetPath);

        return [
            'fixed' => $fixed,
            'output_path' => $outputPath,
            'mismatches' => $audit['mismatches'],
        ];
    }

    /**
     * @return array{fixed: int, invoice_numbers: array<int, string>}
     */
    public function fixDatabase(string $filePath, int $companyId, int $actorId, bool $dryRun = false): array
    {
        $audit = $this->audit($filePath, $companyId);
        $invoiceNumbers = collect($audit['mismatches'])
            ->filter(fn (array $row) => $row['db_status'] === 'mismatch')
            ->pluck('invoice_number')
            ->values()
            ->all();

        if ($dryRun || $invoiceNumbers === []) {
            return [
                'fixed' => 0,
                'invoice_numbers' => $invoiceNumbers,
            ];
        }

        $previousCompanyId = session('current_company_id');
        session(['current_company_id' => $companyId]);

        try {
            $service = new LegacyProjectInvoiceImportService($companyId, $actorId);

            $invoices = Invoice::query()
                ->where('company_id', $companyId)
                ->whereIn('invoice_number', $invoiceNumbers)
                ->where('is_imported', true)
                ->get();

            foreach ($invoices as $invoice) {
                $service->prepareImportedInvoiceForReimport($invoice);
            }

            $importer = new LegacyProjectInvoiceWorkbookImport(
                companyId: $companyId,
                actorId: $actorId,
                forceReimport: true,
            );
            $importer->importFile($filePath);
        } finally {
            if ($previousCompanyId) {
                session(['current_company_id' => $previousCompanyId]);
            } else {
                session()->forget('current_company_id');
            }
        }

        return [
            'fixed' => count($invoiceNumbers),
            'invoice_numbers' => $invoiceNumbers,
        ];
    }

    protected function loadWorkbook(string $filePath): void
    {
        $spreadsheet = IOFactory::load($filePath);
        $invoiceSheet = $spreadsheet->getSheetByName('Invoice');
        $supplementSheet = $spreadsheet->getSheetByName('t');

        if (! $invoiceSheet) {
            throw new \RuntimeException("Sheet 'Invoice' tidak ditemukan di workbook.");
        }

        $invoiceHeader = $this->extractHeaderRow($invoiceSheet);
        $invoiceRows = $this->extractRows($invoiceSheet);
        $supplementHeader = $supplementSheet ? $this->extractHeaderRow($supplementSheet) : [];

        $this->layout = LegacyWorkbookLayout::detect(
            $invoiceHeader,
            array_slice($invoiceRows, 0, 5),
            $supplementHeader,
        );

        $this->supplementByInvoice = [];

        if ($supplementSheet) {
            foreach ($this->extractRows($supplementSheet) as $row) {
                $mapped = $this->mapSupplementRow($row['values']);

                if ($mapped === null || empty($mapped['invoice_number'])) {
                    continue;
                }

                $this->supplementByInvoice[$mapped['invoice_number']] = $mapped + [
                    'row_number' => $row['row_number'],
                ];
            }
        }

        $this->invoiceSheetRows = [];

        foreach ($invoiceRows as $row) {
            $mapped = $this->mapInvoiceSheetRow($row['values']);

            if ($mapped === null) {
                continue;
            }

            $this->invoiceSheetRows[] = $mapped + [
                'row_number' => $row['row_number'],
            ];
        }
    }

    protected function mapInvoiceSheetRow(array $values): ?array
    {
        if ($this->isEmptyRow($values)) {
            return null;
        }

        $invoiceNumber = $this->normalizeDocumentNumber(
            $this->layout->valueAt($values, 'invoice', 'invoice_number'),
        );

        if ($invoiceNumber === null) {
            return null;
        }

        return [
            'invoice_number' => $invoiceNumber,
            'project_reference' => $this->cleanText($this->layout->valueAt($values, 'invoice', 'project_reference')),
            'description' => $this->cleanText($this->layout->valueAt($values, 'invoice', 'item_description')),
            'dpp' => $this->parseMoneyToMinor($this->layout->valueAt($values, 'invoice', 'dpp')),
            'profit' => $this->parseMoneyToMinor($this->layout->valueAt($values, 'invoice', 'profit')),
            'tax_total' => $this->parseMoneyToMinor($this->layout->valueAt($values, 'invoice', 'tax_total')),
            'total' => $this->parseMoneyToMinor($this->layout->valueAt($values, 'invoice', 'total')),
            'paid_value' => $this->parseMoneyToMinor($this->layout->valueAt($values, 'invoice', 'paid_value')),
        ];
    }

    protected function mapSupplementRow(array $values): ?array
    {
        if ($this->isEmptyRow($values)) {
            return null;
        }

        $invoiceNumber = $this->normalizeDocumentNumber(
            ($this->layout->valueAt($values, 'supplement', 'invoice_number') ?: null)
            ?: ($this->layout->valueAt($values, 'supplement', 'invoice_number_alt') ?: null),
        );

        if ($invoiceNumber === null) {
            return null;
        }

        $nominalInvoice = $this->parseMoneyToMinor($this->layout->valueAt($values, 'supplement', 'nominal_invoice'));
        $profit = $this->parseMoneyToMinor($this->layout->valueAt($values, 'supplement', 'profit'));
        $preTaxTotal = $this->parseMoneyToMinor(
            ($this->layout->valueAt($values, 'supplement', 'pre_tax_total') ?: null)
            ?: ($this->layout->valueAt($values, 'supplement', 'nominal_invoice') ?: null),
        );

        if ($profit > 0) {
            $subtotal = max($preTaxTotal - $profit, 0);
            $profitTotal = $profit;
        } else {
            // No explicit Profit column ⇒ entire pretax is DPP (do not invent margin).
            $subtotal = $preTaxTotal > 0 ? $preTaxTotal : $nominalInvoice;
            $profitTotal = 0;
        }

        return [
            'invoice_number' => $invoiceNumber,
            'dpp' => $subtotal,
            'profit' => $profitTotal,
            'tax_total' => $this->parseMoneyToMinor($this->layout->valueAt($values, 'supplement', 'tax_total')),
            'total' => $this->parseMoneyToMinor($this->layout->valueAt($values, 'supplement', 'total')),
            'paid_value' => $this->parseMoneyToMinor($this->layout->valueAt($values, 'supplement', 'paid_value')),
        ];
    }

    protected function amountsMatch(array $invoiceSheet, array $supplement): bool
    {
        return (int) ($invoiceSheet['total'] ?? 0) === (int) ($supplement['total'] ?? 0)
            && (int) ($invoiceSheet['tax_total'] ?? 0) === (int) ($supplement['tax_total'] ?? 0)
            && (int) ($invoiceSheet['paid_value'] ?? 0) === (int) ($supplement['paid_value'] ?? 0);
    }

    /**
     * @param  array<string, int>  $supplementTotalsByInvoice
     */
    protected function detectShiftedSourceInvoice(int $sheetTotal, string $invoiceNumber, array $supplementTotalsByInvoice): ?string
    {
        if ($sheetTotal <= 0) {
            return null;
        }

        foreach ($supplementTotalsByInvoice as $otherInvoiceNumber => $supplementTotal) {
            if ($otherInvoiceNumber === $invoiceNumber) {
                continue;
            }

            if ($sheetTotal === $supplementTotal) {
                return $otherInvoiceNumber;
            }
        }

        return null;
    }

    protected function writeInvoiceSheetAmounts(Worksheet $worksheet, int $rowNumber, array $supplement): void
    {
        $columns = [
            'dpp' => $supplement['dpp'] ?? 0,
            'profit' => $supplement['profit'] ?? 0,
            'tax_total' => $supplement['tax_total'] ?? 0,
            'total' => $supplement['total'] ?? 0,
            'paid_value' => $supplement['paid_value'] ?? 0,
        ];

        foreach ($columns as $key => $amountMinor) {
            if ($amountMinor <= 0) {
                continue;
            }

            $columnIndex = $this->layout->invoiceCol($key) + 1;
            $worksheet->setCellValue([$columnIndex, $rowNumber], $this->formatMoneyForSheet($amountMinor));
        }
    }

    /**
     * @return array{dpp: string, profit: string, tax_total: string, total: string, paid_value: string}
     */
    protected function formatAmountSnapshot(array $amounts): array
    {
        return [
            'dpp' => $this->formatMoneyForSheet((int) ($amounts['dpp'] ?? 0)),
            'profit' => $this->formatMoneyForSheet((int) ($amounts['profit'] ?? 0)),
            'tax_total' => $this->formatMoneyForSheet((int) ($amounts['tax_total'] ?? 0)),
            'total' => $this->formatMoneyForSheet((int) ($amounts['total'] ?? 0)),
            'paid_value' => $this->formatMoneyForSheet((int) ($amounts['paid_value'] ?? 0)),
        ];
    }

    protected function formatMoneyForSheet(int $minor): string
    {
        $major = (int) round($minor / $this->moneySubunit());

        return number_format($major, 0, ',', ',');
    }

    protected function parseMoneyToMinor(mixed $value): int
    {
        return $this->parseMoneyMajorUnits($value) * $this->moneySubunit();
    }

    protected function parseMoneyMajorUnits(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        $value = trim((string) $value);

        if ($value === '' || $value === '#N/A' || $value === '#REF!') {
            return 0;
        }

        $normalized = str_replace(["\u{00A0}", ' '], '', $value);
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');
        $numeric = preg_replace('/[^0-9.,]/', '', $normalized) ?? '';

        if ($numeric === '') {
            return 0;
        }

        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $numeric)) {
            $major = (int) preg_replace('/[.,]/', '', $numeric);

            return $negative ? -$major : $major;
        }

        if (preg_match('/^\d+[.,]\d{1,2}$/', $numeric)) {
            $decimal = (float) str_replace(',', '.', $numeric);
            $major = (int) round($decimal);

            return $negative ? -$major : $major;
        }

        $major = (int) preg_replace('/\D/', '', $numeric);

        return $negative ? -abs($major) : $major;
    }

    protected function moneySubunit(): int
    {
        return (int) (config('money.currencies.IDR.subunit') ?? 100);
    }

    protected function normalizeDocumentNumber(mixed $value): ?string
    {
        $value = strtoupper(trim((string) ($value ?? '')));

        if ($value === '' || $value === '#N/A') {
            return null;
        }

        $value = str_replace(' ', '', $value);

        if (! preg_match('/^[A-Z]{2,}\d{2}-\d+$/', $value)) {
            return null;
        }

        return $value;
    }

    protected function cleanText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';

        return $value !== '' ? $value : null;
    }

    protected function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->cleanText($value) !== null) {
                return false;
            }
        }

        return true;
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
