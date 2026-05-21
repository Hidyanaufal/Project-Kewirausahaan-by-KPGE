<?php

namespace App\Services;

use App\Contracts\ExportableReport;
use App\Contracts\HasSummaryReport;
use App\Models\Company;
use App\Transformers\CashFlowStatementReportTransformer;
use App\Utilities\Currency\CurrencyConverter;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use League\Csv\Bom;
use League\Csv\CannotInsertRecord;
use League\Csv\Exception;
use League\Csv\Writer;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportService
{
    /**
     * Parse Indonesian formatted currency string (e.g. "Rp2.151.600.000,00") into float for Excel.
     * Returns null if the value does not look like a currency/decimal string.
     */
    private function tryParseIndonesianAmountString(string $value): ?float
    {
        $raw = trim($value);

        // Skip explicit string-marked values used to preserve account codes.
        if (str_starts_with($raw, '__str')) {
            return null;
        }

        $looksCurrency = str_contains($raw, 'Rp') || str_contains($raw, 'rp');
        $hasDecimalComma = str_contains($raw, ',');
        $hasParens = str_contains($raw, '(') && str_contains($raw, ')');

        // If it doesn't look like money/decimal, do not parse (prevents account codes like "1.100.03").
        if (! $looksCurrency && ! $hasDecimalComma && ! $hasParens) {
            return null;
        }

        $negative = false;
        if ($hasParens) {
            $negative = true;
        }
        if (str_starts_with($raw, '-')) {
            $negative = true;
        }

        $clean = $raw;
        $clean = str_replace(['Rp', 'rp', 'RP'], '', $clean);
        $clean = str_replace(['(', ')', ' '], '', $clean);
        $clean = ltrim($clean, '+-');

        // Remove thousand separators and normalize decimal.
        $clean = str_replace('.', '', $clean);
        $clean = str_replace(',', '.', $clean);

        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }

        $num = (float) $clean;

        return $negative ? -abs($num) : $num;
    }

    /**
     * Normalize a cell for Excel export:
     * - "__strXXXX" => string "XXXX" (preserve leading zeros / dots)
     * - "Rp..." => float number
     */
    public function normalizeCellForExcel(mixed $cell): mixed
    {
        if (! is_string($cell)) {
            return $cell;
        }

        if (str_starts_with($cell, '__str')) {
            return str_replace('__str', '', $cell);
        }

        $parsed = $this->tryParseIndonesianAmountString($cell);
        if ($parsed !== null) {
            return $parsed;
        }

        return $cell;
    }

    /**
     * Flatten a report row (assoc) to a simple list of values.
     *
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    private function flattenReportRowForExcel(array $row): array
    {
        $flattened = array_map(static function ($value) {
            return (is_array($value) && array_key_exists('name', $value))
                ? $value['name']
                : $value;
        }, $row);

        return array_values($flattened);
    }

    /**
     * Convert an associative totals row to an ordered list aligned to columns.
     *
     * @param  array<string, mixed>  $totals
     * @return list<mixed>
     */
    private function orderTotalsRowForExcel(ExportableReport $report, array $totals): array
    {
        $ordered = [];
        foreach ($report->getColumns() as $column) {
            $name = $column->getName();
            $value = $totals[$name] ?? '';
            if (is_array($value) && array_key_exists('name', $value)) {
                $value = $value['name'];
            }
            $ordered[] = $value;
        }

        return $ordered;
    }

    /**
     * Build the "legacy" detail table rows used by exportToExcel() (non-summary path).
     * This intentionally mirrors the existing legacy XLSX content generation to allow
     * safe migration to exportToExcelEnhanced() without changing report data.
     *
     * @return array{columns: list<mixed>, rows: list<list<mixed>>}
     */
    public function buildLegacyDetailTableForExcel(ExportableReport $report): array
    {
        $columns = $report->getHeaders();
        $rows = [];

        foreach ($report->getCategories() as $category) {
            if ($category->header) {
                $rows[] = $category->header;
            }

            foreach ($category->data as $row) {
                $flattened = array_map(static function ($value) {
                    return (is_array($value) && array_key_exists('name', $value))
                        ? $value['name']
                        : $value;
                }, $row);

                $rows[] = array_values($flattened);
            }

            // Include category totals when available (matches UI section totals).
            if (filled($category->summary)) {
                $rows[] = $this->flattenReportRowForExcel($category->summary);
            }
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * Export XLSX using the Enhanced format (3 header rows + table header),
     * while validating that the table content matches the legacy exportToExcel() output.
     *
     * If a mismatch is detected, this method throws with a short diff message so callers
     * can fall back to the legacy exporter.
     */
    public function exportToExcelEnhancedFromReport(
        Company $company,
        ExportableReport $report,
        ?string $startDate = null,
        ?string $endDate = null,
    ): BinaryFileResponse {
        if ($startDate && $endDate) {
            $reportPeriod = 'Date Range: ' . Carbon::parse($startDate)->toDefaultDateFormat()
                . ' to ' . Carbon::parse($endDate)->toDefaultDateFormat();
            $dateLabelForFilename = Carbon::parse($startDate)->toDateString() . ' to ' . Carbon::parse($endDate)->toDateString();
        } else {
            $reportPeriod = 'As of ' . Carbon::parse($endDate)->toDefaultDateFormat();
            $dateLabelForFilename = Carbon::parse($endDate)->toDateString();
        }

        $legacy = $this->buildLegacyDetailTableForExcel($report);
        // Normalize to numeric-indexed arrays to avoid "string + int" issues when headers/rows are associative.
        $columns = array_values($legacy['columns']);
        $legacyRows = array_map(static fn (array $row) => array_values($row), $legacy['rows']);

        /**
         * Add export-only column "account_number" when the report already includes account_code.
         * This does not change report logic; it only duplicates the account code into a new column.
         */
        $accountCodeIndex = null;
        foreach ($columns as $i => $label) {
            if (! is_string($label)) {
                continue;
            }
            $normalized = strtolower(trim($label));
            if (in_array($normalized, ['account code', 'account no', 'account no.', 'gl account no.', 'account number'], true)) {
                $accountCodeIndex = $i;
                break;
            }
            if ($normalized === 'account_code') {
                $accountCodeIndex = $i;
                break;
            }
        }

        if ($accountCodeIndex !== null) {
            array_splice($columns, $accountCodeIndex + 1, 0, ['account_number']);

            $legacyRows = array_map(static function (array $row) use ($accountCodeIndex) {
                $value = $row[$accountCodeIndex] ?? '';
                $newRow = $row;
                array_splice($newRow, $accountCodeIndex + 1, 0, [$value]);
                return $newRow;
            }, $legacyRows);
        }

        // Build enhanced rows with optional styling for section headers like "Assets", "Liabilities", "Equity".
        $accountNameIndex = null;
        foreach ($columns as $i => $label) {
            if (! is_string($label)) {
                continue;
            }
            $normalized = strtolower(trim($label));
            if (in_array($normalized, ['account', 'account name', 'accounts'], true)) {
                $accountNameIndex = $i;
                break;
            }
        }

        $sectionHeaderNames = [
            'assets',
            'liabilities',
            'equity',
            'revenue',
            'expenses',
            'expense',
            'cost of goods sold',
            'cogs',
        ];
        $rowsForEnhanced = [];
        foreach ($legacyRows as $row) {
            $rowWrapper = ['data' => $row];

            if ($accountNameIndex !== null) {
                $nameCell = $row[$accountNameIndex] ?? null;
                $nameCellString = is_string($nameCell) ? strtolower(trim($nameCell)) : null;

                if ($nameCellString !== null && in_array($nameCellString, $sectionHeaderNames, true)) {
                    $rowWrapper['style'] = ['font-weight' => 'bold'];
                }

                // Style section totals like "Total Revenue", "Total Expenses", etc.
                if ($nameCellString !== null && str_starts_with($nameCellString, 'total ')) {
                    $rowWrapper['style'] = ['font-weight' => 'bold', 'color' => 'rgb(6, 6, 174)'];
                }
            }

            $rowsForEnhanced[] = $rowWrapper;
        }

        // Append grand total row (matches UI footer) when provided by transformer.
        $overallTotals = $report->getOverallTotals();
        if (filled($overallTotals)) {
            $totalsRow = $this->orderTotalsRowForExcel($report, $overallTotals);
            if ($accountCodeIndex !== null) {
                // Insert empty account_number cell next to account_code.
                array_splice($totalsRow, $accountCodeIndex + 1, 0, ['']);
            }

            $rowsForEnhanced[] = [
                'data' => $totalsRow,
                'style' => ['font-weight' => 'bold', 'color' => 'rgb(6, 6, 174)'],
            ];

            // Also extend the legacy representation for validation, so we still verify the main content
            // while allowing this extra grand total row.
            $legacyRows[] = $totalsRow;
        }

        // Validate: same column headers + same row content.
        // (Enhanced exporter changes layout/styling, not data.)
        // Validate: the base columns match legacy; we allow the extra export-only column account_number.
        $expectedColumns = array_values($report->getHeaders());
        if ($accountCodeIndex !== null) {
            array_splice($expectedColumns, $accountCodeIndex + 1, 0, ['account_number']);
        }
        if ($columns !== $expectedColumns) {
            throw new \RuntimeException('Mismatch detected: table columns changed between legacy and enhanced export.');
        }
        if ($legacyRows !== array_map(static fn (array $r) => $r['data'], $rowsForEnhanced)) {
            // Find first differing row for a concise error.
            $max = max(count($legacyRows), count($rowsForEnhanced));
            for ($i = 0; $i < $max; $i++) {
                $a = $legacyRows[$i] ?? null;
                $b = $rowsForEnhanced[$i]['data'] ?? null;
                if ($a !== $b) {
                    $idx = $i + 1; // 1-based for humans
                    throw new \RuntimeException("Mismatch detected at table row #{$idx}. Legacy and enhanced row data differ.");
                }
            }

            throw new \RuntimeException('Mismatch detected: legacy and enhanced rows differ.');
        }

        $timestamp = company_now()->format('Y-m-d_H-i-s');
        $filename = $company->name . ' ' . $report->getTitle() . ' ' . $dateLabelForFilename . ' ' . $timestamp . '.xlsx';

        return $this->exportToExcelEnhanced([
            // Preserve the legacy filename format exactly.
            'filename' => $filename,
            'company_name' => $company->name,
            'report_title' => $report->getTitle(),
            'report_period' => $reportPeriod,
            'columns' => $columns,
            'rows' => $rowsForEnhanced,
        ]);
    }
    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    public function flattenSummaryRowForExcel(HasSummaryReport $report, array $row, bool $parseAmounts = true): array
    {
        $flat = [];

        foreach ($report->getSummaryColumns() as $column) {
            $name = $column->getName();
            $value = $row[$name] ?? '';

            if (is_array($value) && array_key_exists('name', $value)) {
                $value = $value['name'];
            }

            if (
                $parseAmounts
                && $name !== 'account_name'
                && ! $column->isDate()
                && $value !== '' && $value !== null
            ) {
                if (is_string($value) && CurrencyConverter::isValidAmount($value)) {
                    $value = CurrencyConverter::convertToFloat($value);
                }
            }

            $flat[] = $value;
        }

        return $flat;
    }

    public function exportToCsv(Company $company, ExportableReport $report, ?string $startDate = null, ?string $endDate = null, ?string $activeTab = null): StreamedResponse
    {
        if ($startDate && $endDate) {
            $formattedStartDate = Carbon::parse($startDate)->toDateString();
            $formattedEndDate = Carbon::parse($endDate)->toDateString();
            $dateLabel = $formattedStartDate . ' to ' . $formattedEndDate;
        } else {
            $formattedAsOfDate = Carbon::parse($endDate)->toDateString();
            $dateLabel = $formattedAsOfDate;
        }

        $timestamp = company_now()->format('Y-m-d_H-i-s');

        $filename = $company->name . ' ' . $report->getTitle() . ' ' . $dateLabel . ' ' . $timestamp . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($startDate, $endDate, $report, $company, $activeTab) {
            $csv = Writer::createFromStream(fopen('php://output', 'wb'));
            $csv->setOutputBOM(Bom::Utf8);

            if ($startDate && $endDate) {
                $defaultStartDateFormat = Carbon::parse($startDate)->toDefaultDateFormat();
                $defaultEndDateFormat = Carbon::parse($endDate)->toDefaultDateFormat();
                $dateLabel = 'Date Range: ' . $defaultStartDateFormat . ' to ' . $defaultEndDateFormat;
            } else {
                $dateLabel = 'As of ' . Carbon::parse($endDate)->toDefaultDateFormat();
            }

            $csv->insertOne([$report->getTitle()]);
            $csv->insertOne([$company->name]);
            $csv->insertOne([$dateLabel]);
            $csv->insertOne([]);

            if ($activeTab === 'summary') {
                $this->writeSummaryTableToCsv($csv, $report);
            } else {
                $this->writeDetailedTableToCsv($csv, $report);
            }
        };

        return response()->streamDownload($callback, $filename, $headers);
    }

    /**
     * @throws CannotInsertRecord
     * @throws Exception
     */
    protected function writeSummaryTableToCsv(Writer $csv, ExportableReport $report): void
    {
        /** @var HasSummaryReport $report */
        $csv->insertOne($report->getSummaryHeaders());

        foreach ($report->getSummaryCategories() as $category) {
            if (filled($category->header)) {
                $csv->insertOne($category->header);
            }

            foreach ($category->types ?? [] as $type) {
                $csv->insertOne($type->summary);
            }

            if (filled($category->summary)) {
                $csv->insertOne($category->summary);
            }

            if ($category->summary['account_name'] === 'Cost of Goods Sold' && method_exists($report, 'getGrossProfit') && filled($report->getGrossProfit())) {
                $csv->insertOne($report->getGrossProfit());
            }

            if (filled($category->header)) {
                $csv->insertOne([]);
            }
        }

        if (method_exists($report, 'getSummaryOverviewHeaders') && filled($report->getSummaryOverviewHeaders())) {
            $this->writeSummaryOverviewTableToCsv($csv, $report);
        }

        if (filled($report->getSummaryOverallTotals())) {
            $csv->insertOne($report->getSummaryOverallTotals());
        }
    }

    /**
     * @throws CannotInsertRecord
     * @throws Exception
     */
    protected function writeDetailedTableToCsv(Writer $csv, ExportableReport $report): void
    {
        $csv->insertOne($report->getHeaders());

        foreach ($report->getCategories() as $category) {
            $this->writeDataRowsToCsv($csv, $category->header, $category->data, $report->getColumns());

            foreach ($category->types ?? [] as $type) {
                $this->writeDataRowsToCsv($csv, $type->header, $type->data, $report->getColumns());

                if (filled($type->summary)) {
                    $csv->insertOne($type->summary);
                }
            }

            if (filled($category->summary)) {
                $csv->insertOne($category->summary);

                $csv->insertOne([]);
            }
        }

        if (method_exists($report, 'getOverviewHeaders') && filled($report->getOverviewHeaders())) {
            $this->writeOverviewTableToCsv($csv, $report);
        }

        if (filled($report->getOverallTotals())) {
            $csv->insertOne($report->getOverallTotals());
        }
    }

    /**
     * @throws CannotInsertRecord
     * @throws Exception
     */
    protected function writeSummaryOverviewTableToCsv(Writer $csv, ExportableReport $report): void
    {
        /** @var CashFlowStatementReportTransformer $report */
        $headers = $report->getSummaryOverviewHeaders();

        if (filled($headers)) {
            $csv->insertOne($headers);
        }

        foreach ($report->getSummaryOverview() as $overviewCategory) {
            if (filled($overviewCategory->header)) {
                $this->writeDataRowsToCsv($csv, $overviewCategory->header, $overviewCategory->data, $report->getSummaryColumns());
            }

            if (filled($overviewCategory->summary)) {
                $csv->insertOne($overviewCategory->summary);
            }

            if ($overviewCategory->summary['account_name'] === 'Starting Balance') {
                foreach ($report->getSummaryOverviewAlignedWithColumns() as $summaryRow) {
                    $row = [];

                    foreach ($report->getSummaryColumns() as $column) {
                        $columnName = $column->getName();
                        $row[] = $summaryRow[$columnName] ?? '';
                    }

                    if (array_filter($row)) {
                        $csv->insertOne($row);
                    }
                }
            }
        }
    }

    /**
     * @throws CannotInsertRecord
     * @throws Exception
     */
    protected function writeOverviewTableToCsv(Writer $csv, ExportableReport $report): void
    {
        /** @var CashFlowStatementReportTransformer $report */
        $headers = $report->getOverviewHeaders();

        if (filled($headers)) {
            $csv->insertOne($headers);
        }

        foreach ($report->getOverview() as $overviewCategory) {
            if (filled($overviewCategory->header)) {
                $this->writeDataRowsToCsv($csv, $overviewCategory->header, $overviewCategory->data, $report->getColumns());
            }

            if (filled($overviewCategory->summary)) {
                $csv->insertOne($overviewCategory->summary);
            }

            if ($overviewCategory->header['account_name'] === 'Starting Balance') {
                foreach ($report->getOverviewAlignedWithColumns() as $summaryRow) {
                    $row = [];

                    foreach ($report->getColumns() as $column) {
                        $columnName = $column->getName();
                        $row[] = $summaryRow[$columnName] ?? '';
                    }

                    if (array_filter($row)) {
                        $csv->insertOne($row);
                    }
                }
            }
        }
    }

    /**
     * @throws CannotInsertRecord
     * @throws Exception
     */
    protected function writeDataRowsToCsv(Writer $csv, ?array $header, array $data, array $columns): void
    {
        if ($header) {
            if (isset($header[0]) && is_array($header[0])) {
                foreach ($header as $headerRow) {
                    $csv->insertOne($headerRow);
                }
            } else {
                $csv->insertOne($header);
            }
        }

        // Output data rows
        foreach ($data as $rowData) {
            $row = [];

            foreach ($columns as $column) {
                $columnName = $column->getName();
                $cell = $rowData[$columnName] ?? '';

                if ($column->isDate()) {
                    try {
                        $row[] = Carbon::parse($cell)->toDateString();
                    } catch (InvalidFormatException) {
                        $row[] = $cell;
                    }
                } elseif (is_array($cell)) {
                    $row[] = $cell['name'] ?? $cell['description'] ?? '';
                } else {
                    $row[] = $cell;
                }
            }

            $csv->insertOne($row);
        }
    }

    public function exportToPdf(
        Company $company,
        ExportableReport $report,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $activeTab = null
    ): StreamedResponse {
        if ($startDate && $endDate) {
            $formattedStartDate = Carbon::parse($startDate)->toDateString();
            $formattedEndDate = Carbon::parse($endDate)->toDateString();
            $dateLabel = $formattedStartDate . ' to ' . $formattedEndDate;
        } else {
            $formattedAsOfDate = Carbon::parse($endDate)->toDateString();
            $dateLabel = $formattedAsOfDate;
        }

        $timestamp = company_now()->format('Y-m-d_H-i-s');

        $filename = $company->name . ' ' . $report->getTitle() . ' ' . $dateLabel . ' ' . $timestamp . '.pdf';

        $view = $activeTab === 'summary' ? $report->getSummaryPdfView() : $report->getPdfView();

        $pdf = SnappyPdf::loadView($view, [
            'company' => $company,
            'report' => $report,
            'startDate' => $startDate ? Carbon::parse($startDate)->toDefaultDateFormat() : null,
            'endDate' => $endDate ? Carbon::parse($endDate)->toDefaultDateFormat() : null,
        ]);

        $pdf->setOptions([
            'dpi' => 96,
            'zoom' => 1,
            'enable-local-file-access' => true,
            'encoding' => 'UTF-8',
            'margin-top' => 5,
            'margin-bottom' => 5,
            'margin-left' => 5,
            'margin-right' => 5,
            'disable-smart-shrinking' => true,
        ]);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $filename);
    }

    public function exportToExcel(
        Company $company,
        ExportableReport $report,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $activeTab = null
    ): BinaryFileResponse {
        if ($startDate && $endDate) {
            $formattedStartDate = Carbon::parse($startDate)->toDateString();
            $formattedEndDate = Carbon::parse($endDate)->toDateString();
            $dateLabel = $formattedStartDate . ' to ' . $formattedEndDate;
        } else {
            $formattedAsOfDate = Carbon::parse($endDate)->toDateString();
            $dateLabel = $formattedAsOfDate;
        }

        $timestamp = company_now()->format('Y-m-d_H-i-s');

        $filename = $company->name . ' ' . $report->getTitle() . ' ' . $dateLabel . ' ' . $timestamp . '.xlsx';
        $exportService = $this;
        $export = new class($company, $report, $startDate, $endDate, $activeTab, $exportService) implements \Maatwebsite\Excel\Concerns\FromArray, \Maatwebsite\Excel\Concerns\WithEvents, \Maatwebsite\Excel\Concerns\WithStyles, \Maatwebsite\Excel\Concerns\WithTitle
        {
            public function __construct(
                protected Company $company,
                protected ExportableReport $report,
                protected ?string $startDate,
                protected ?string $endDate,
                protected ?string $activeTab,
                protected ExportService $exportService,
            ) {}

            public function array(): array
            {
                $rows = [];

                if ($this->startDate && $this->endDate) {
                    $defaultStartDateFormat = Carbon::parse($this->startDate)->toDefaultDateFormat();
                    $defaultEndDateFormat = Carbon::parse($this->endDate)->toDefaultDateFormat();
                    $dateLabel = 'Date Range: ' . $defaultStartDateFormat . ' to ' . $defaultEndDateFormat;
                } else {
                    $dateLabel = 'As of ' . Carbon::parse($this->endDate)->toDefaultDateFormat();
                }

                $rows[] = [$this->report->getTitle()];
                $rows[] = [$this->company->name];
                $rows[] = [$dateLabel];

                if ($this->activeTab === 'summary' && $this->report instanceof HasSummaryReport) {
                    /** @var HasSummaryReport $summaryReport */
                    $summaryReport = $this->report;

                    $rows[] = $this->exportService->flattenSummaryRowForExcel($summaryReport, $summaryReport->getSummaryHeaders(), false);

                    foreach ($summaryReport->getSummaryCategories() as $categoryName => $category) {
                        if (filled($category->header)) {
                            $rows[] = $this->exportService->flattenSummaryRowForExcel($summaryReport, $category->header);
                        }

                        foreach ($category->types ?? [] as $type) {
                            $rows[] = $this->exportService->flattenSummaryRowForExcel($summaryReport, $type->summary);
                        }

                        if (filled($category->summary)) {
                            $rows[] = $this->exportService->flattenSummaryRowForExcel($summaryReport, $category->summary);
                        }

                        if ($categoryName === 'Cost of Goods Sold' && method_exists($summaryReport, 'getGrossProfit') && filled($summaryReport->getGrossProfit())) {
                            $rows[] = $this->exportService->flattenSummaryRowForExcel($summaryReport, $summaryReport->getGrossProfit());
                        }
                    }

                    if (filled($summaryReport->getSummaryOverallTotals())) {
                        $rows[] = $this->exportService->flattenSummaryRowForExcel($summaryReport, $summaryReport->getSummaryOverallTotals());
                    }
                } else {
                    // Satu baris kosong sebelum tabel detail (summary tidak memakai baris ini agar baris header = 4)
                    $rows[] = [];

                    $rows[] = $this->report->getHeaders();
                    foreach ($this->report->getCategories() as $category) {
                        if ($category->header) {
                            $rows[] = array_map(fn ($c) => $this->exportService->normalizeCellForExcel($c), array_values($category->header));
                        }
                        foreach ($category->data as $row) {
                            $flattened = array_map(function ($value) {
                                return (is_array($value) && array_key_exists('name', $value))
                                    ? $value['name']
                                    : $value;
                            }, $row);
                            $rows[] = array_map(fn ($c) => $this->exportService->normalizeCellForExcel($c), array_values($flattened));
                        }

                        if (filled($category->summary)) {
                            $rows[] = array_map(fn ($c) => $this->exportService->normalizeCellForExcel($c), array_values($category->summary));
                        }
                    }
                }

                return $rows;
            }

            public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
            {
                if ($this->activeTab !== 'summary' || ! ($this->report instanceof HasSummaryReport)) {
                    return [];
                }

                $highestRow = $sheet->getHighestRow();
                $highestCol = $sheet->getHighestColumn();

                $sheet->mergeCells('A1:' . $highestCol . '1');
                $sheet->mergeCells('A2:' . $highestCol . '2');
                $sheet->mergeCells('A3:' . $highestCol . '3');

                $sheet->getStyle('A1')->applyFromArray([
                    'font' => [
                        'name' => 'Roboto',
                        'size' => 15,
                        'bold' => true,
                        'color' => ['rgb' => 'B30202'],
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $sheet->getStyle('A2')->applyFromArray([
                    'font' => [
                        'name' => 'Roboto',
                        'size' => 12,
                        'bold' => true,
                        'color' => ['rgb' => '333333'],
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $sheet->getStyle('A3')->applyFromArray([
                    'font' => [
                        'name' => 'Roboto',
                        'size' => 12,
                        'italic' => true,
                        'color' => ['rgb' => '333333'],
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                // Baris 1–3: judul; baris 4: header kolom (ACCOUNTS / periode). Tanpa baris kosong sebelum header.
                $headerRow = 4;
                $sheet->getStyle("A{$headerRow}:" . $highestCol . $headerRow)->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '0606AE'],
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                    ],
                    'borders' => [
                        'bottom' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                ]);

                $tableRange = "A{$headerRow}:" . $highestCol . $highestRow;
                $sheet->getStyle($tableRange)->getBorders()->getAllBorders()
                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

                $firstDataRow = $headerRow + 1;
                if ($firstDataRow <= $highestRow) {
                    foreach (range('B', $highestCol) as $col) {
                        $sheet->getStyle("{$col}{$firstDataRow}:{$col}{$highestRow}")
                            ->getNumberFormat()->setFormatCode('#,##0.00');
                        $sheet->getStyle("{$col}{$firstDataRow}:{$col}{$highestRow}")
                            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
                    }
                }

                $sheet->getStyle("A{$headerRow}:A{$highestRow}")
                    ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

                foreach (range('A', $highestCol) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                return [];
            }

            public function registerEvents(): array
            {
                return [
                    \Maatwebsite\Excel\Events\AfterSheet::class => function (\Maatwebsite\Excel\Events\AfterSheet $event): void {
                        if ($this->activeTab !== 'summary' || ! ($this->report instanceof HasSummaryReport)) {
                            return;
                        }

                        $event->sheet->freezePane('A5');
                    },
                ];
            }

            public function title(): string
            {
                return $this->report->getTitle();
            }
        };

        return Excel::download($export, $filename);
    }

    public function exportToPdfEnhanced(array $data): StreamedResponse
    {
        $pdf = SnappyPdf::loadView('pdf.reports-general', [
            'data' => $data,
        ]);

        $pdfOptions = [
            'dpi' => 96,
            'zoom' => 1,
            'enable-local-file-access' => true,
            'encoding' => 'UTF-8',
            'margin-top' => 5,
            'margin-bottom' => 5,
            'margin-left' => 5,
            'margin-right' => 5,
            'disable-smart-shrinking' => true,
        ];

        if (! empty($data['pdf_table']['orientation'])) {
            $pdfOptions['orientation'] = $data['pdf_table']['orientation'];
        }

        $pdf->setOptions($pdfOptions);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $data['filename']);
    }

    public function exportToExcelEnhanced(array $data): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $filename = str_replace('.pdf', '.xlsx', $data['filename']);
        $export = new class($data) implements \Maatwebsite\Excel\Concerns\FromArray, \Maatwebsite\Excel\Concerns\WithEvents, \Maatwebsite\Excel\Concerns\WithStyles, \Maatwebsite\Excel\Concerns\WithTitle
        {
            use \Maatwebsite\Excel\Concerns\RegistersEventListeners;

            public function __construct(public array $data) {}

            public function array(): array
            {
                $rows = [];

                // Header umum laporan (baris 1-3)
                $rows[] = [$this->data['company_name']];
                $rows[] = [$this->data['report_title']];
                $rows[] = [$this->data['report_period']];

                // Header tabel (baris 4)
                $rows[] = $this->data['columns'];

                // Data isi tabel
                foreach ($this->data['rows'] as $row) {
                    $normalizedRow = [];
                    foreach (($row['data'] ?? []) as $cell) {
                        if (is_string($cell) && str_starts_with($cell, '__str')) {
                            $normalizedRow[] = str_replace('__str', '', $cell);
                            continue;
                        }

                        if (is_string($cell)) {
                            // Parse "Rp..." strings into numeric values so Excel can calculate.
                            $raw = $cell;
                            $clean = trim($raw);
                            $looksCurrency = str_contains($clean, 'Rp') || str_contains($clean, 'rp');
                            $hasDecimalComma = str_contains($clean, ',');
                            $hasParens = str_contains($clean, '(') && str_contains($clean, ')');
                            if ($looksCurrency || $hasDecimalComma || $hasParens) {
                                $negative = false;
                                if ($hasParens || str_starts_with($clean, '-')) {
                                    $negative = true;
                                }
                                $clean = str_replace(['Rp', 'rp', 'RP'], '', $clean);
                                $clean = str_replace(['(', ')', ' '], '', $clean);
                                $clean = ltrim($clean, '+-');
                                $clean = str_replace('.', '', $clean);
                                $clean = str_replace(',', '.', $clean);
                                if ($clean !== '' && is_numeric($clean)) {
                                    $num = (float) $clean;
                                    $normalizedRow[] = $negative ? -abs($num) : $num;
                                    continue;
                                }
                            }
                        }

                        $normalizedRow[] = $cell;
                    }

                    $rows[] = $normalizedRow;
                }

                return $rows;
            }

            public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
            {
                $highestRow = $sheet->getHighestRow();
                $highestCol = $sheet->getHighestColumn();

                // Hitung lebar header (biar bisa merge cell A1 sampai kolom terakhir)
                $mergeRange = 'A1:' . $highestCol . '1';
                $sheet->mergeCells($mergeRange);
                $sheet->mergeCells('A2:' . $highestCol . '2');
                $sheet->mergeCells('A3:' . $highestCol . '3');

                // ===== STYLE UNTUK JUDUL LAPORAN =====
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => [
                        'name' => 'Roboto',
                        'size' => 12,
                        'bold' => true,
                        'color' => ['rgb' => '333333'],
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $sheet->getStyle('A2')->applyFromArray([
                    'font' => [
                        'name' => 'Roboto',
                        'size' => 15,
                        'bold' => true,
                        'color' => ['rgb' => 'B30202'], // merah seperti PDF
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $sheet->getStyle('A3')->applyFromArray([
                    'font' => [
                        'name' => 'Roboto',
                        'size' => 12,
                        'italic' => true,
                        'color' => ['rgb' => '333333'],
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                // ====== HEADER TABLE ======
                $sheet->getStyle('A4:' . $highestCol . '4')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '0606AE'], // biru seperti PDF
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                    ],
                    'borders' => [
                        'bottom' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                ]);

                // ====== BORDER UNTUK SEMUA DATA ======
                $tableRange = 'A4:' . $highestCol . $highestRow;
                $sheet->getStyle($tableRange)->getBorders()->getAllBorders()
                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

                // ====== FORMAT ANGKA ======
                $numericColumns = $this->data['numeric_columns'] ?? null;
                if (is_array($numericColumns) && ! empty($numericColumns)) {
                    foreach ($numericColumns as $col) {
                        if (! is_string($col) || $col === '') {
                            continue;
                        }
                        $col = strtoupper($col);
                        $sheet->getStyle("{$col}5:{$col}{$highestRow}")
                            ->getNumberFormat()->setFormatCode('#,##0.00');
                        $sheet->getStyle("{$col}5:{$col}{$highestRow}")
                            ->getAlignment()->setHorizontal('right');
                    }
                } else {
                    foreach (range('B', $highestCol) as $col) {
                        $sheet->getStyle("{$col}5:{$col}{$highestRow}")
                            ->getNumberFormat()->setFormatCode('#,##0.00');
                        $sheet->getStyle("{$col}5:{$col}{$highestRow}")
                            ->getAlignment()->setHorizontal('right');
                    }
                }

                // ====== TEKS RATA KIRI UNTUK KOLOM A ======
                $sheet->getStyle('A4:A' . $highestRow)
                    ->getAlignment()->setHorizontal('left');

                // ====== BOLD BARIS SESUAI DATA ======
                foreach ($this->data['rows'] as $index => $row) {
                    $rowNumber = $index + 5;
                    $cellRange = "A{$rowNumber}:" . $highestCol . "{$rowNumber}";
                    if (isset($row['style']['font-weight']) && $row['style']['font-weight'] === 'bold') {
                        $sheet->getStyle($cellRange)->getFont()->setBold(true);
                    }
                }

                // ====== AUTO SIZE KOLOM ======
                foreach (range('A', $highestCol) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                return [];
            }

            public static function afterSheet(\Maatwebsite\Excel\Events\AfterSheet $event): void
            {
                $event->sheet->freezePane('A5');

                $sheet = $event->sheet->getDelegate();
                $export = $event->getConcernable();
                if (! is_object($export) || ! isset($export->data['rows'])) {
                    return;
                }

                $mergeColumns = $export->data['excel_merge_columns'] ?? [];
                $wrapColumns = $export->data['excel_wrap_columns'] ?? [];
                $dataStartRow = 5;
                $highestRow = $sheet->getHighestRow();

                if ($mergeColumns !== []) {
                    $currentKey = null;
                    $groupStart = null;
                    $rowIndex = 0;

                    foreach ($export->data['rows'] as $row) {
                        $sheetRow = $dataStartRow + $rowIndex;
                        $key = ($row['excel_merge'] ?? false) ? ($row['excel_merge_key'] ?? null) : null;

                        if ($key !== $currentKey) {
                            if ($currentKey !== null && $groupStart !== null && $sheetRow - 1 > $groupStart) {
                                foreach ($mergeColumns as $col) {
                                    $sheet->mergeCells("{$col}{$groupStart}:{$col}" . ($sheetRow - 1));
                                }
                            }
                            $currentKey = $key;
                            $groupStart = $key !== null ? $sheetRow : null;
                        }

                        $rowIndex++;
                    }

                    if ($currentKey !== null && $groupStart !== null && $highestRow > $groupStart) {
                        foreach ($mergeColumns as $col) {
                            $sheet->mergeCells("{$col}{$groupStart}:{$col}{$highestRow}");
                        }
                    }
                }

                if ($wrapColumns !== [] && $highestRow >= $dataStartRow) {
                    foreach ($wrapColumns as $col) {
                        $sheet->getStyle("{$col}{$dataStartRow}:{$col}{$highestRow}")
                            ->getAlignment()
                            ->setWrapText(true)
                            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
                    }
                }
            }

            public function title(): string
            {
                return $this->data['report_title'] ?? 'Report';
            }
        };

        return \Maatwebsite\Excel\Facades\Excel::download($export, $filename);
    }
}
