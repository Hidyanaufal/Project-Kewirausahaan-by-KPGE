<?php

namespace App\Services;

use App\Contracts\ExportableReport;
use App\Contracts\HasSummaryReport;
use App\Models\Company;
use App\Transformers\CashFlowStatementReportTransformer;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use League\Csv\Bom;
use League\Csv\CannotInsertRecord;
use League\Csv\Exception;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportService
{
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

    public function exportToPdf(Company $company, ExportableReport $report, ?string $startDate = null, ?string $endDate = null, ?string $activeTab = null): StreamedResponse
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

        $filename = $company->name . ' ' . $report->getTitle() . ' ' . $dateLabel . ' ' . $timestamp . '.pdf';

        $view = $activeTab === 'summary' ? $report->getSummaryPdfView() : $report->getPdfView();

        $pdf = SnappyPdf::loadView($view, [
            'company' => $company,
            'report' => $report,
            'startDate' => $startDate ? Carbon::parse($startDate)->toDefaultDateFormat() : null,
            'endDate' => $endDate ? Carbon::parse($endDate)->toDefaultDateFormat() : null,
        ]);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->inline();
        }, $filename);
    }

    public function exportToExcel(Company $company, ExportableReport $report, ?string $startDate = null, ?string $endDate = null, ?string $activeTab = null): BinaryFileResponse
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

        $filename = $company->name . ' ' . $report->getTitle() . ' ' . $dateLabel . ' ' . $timestamp . '.xlsx';

        // bikin exportable class on the fly
        $export = new class($company, $report, $startDate, $endDate, $activeTab) implements \Maatwebsite\Excel\Concerns\FromArray {
            public function __construct(
                protected Company $company,
                protected ExportableReport $report,
                protected ?string $startDate,
                protected ?string $endDate,
                protected ?string $activeTab,
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
                $rows[] = [];

                if ($this->activeTab === 'summary') {
                    $rows[] = $this->report->getSummaryHeaders();
                    foreach ($this->report->getSummaryCategories() as $category) {
                        if (filled($category->header)) {
                            $rows[] = $category->header;
                        }

                        foreach ($category->types ?? [] as $type) {
                            $rows[] = $type->summary;
                        }

                        if (filled($category->summary)) {
                            $rows[] = $category->summary;
                        }
                    }
                } else {
                    $rows[] = $this->report->getHeaders();
                    foreach ($this->report->getCategories() as $category) {
                        if ($category->header) {
                            $rows[] = $category->header;
                        }
                        foreach ($category->data as $row) {
                            $rows[] = array_values($row);
                        }
                    }
                }

                return $rows;
            }
        };

        return Excel::download($export, $filename);
    }

    public function exportToPdf2(Company $company, ExportableReport $report, ?string $startDate = null, ?string $endDate = null): StreamedResponse
    {
        $formattedStartDate = Carbon::parse($startDate)->toDateString();
        $formattedEndDate = Carbon::parse($endDate)->toDateString();
        $dateLabel = $formattedStartDate . ' to ' . $formattedEndDate;
        $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
        $filename = $company->name . ' ' . $report->getTitle() . ' ' . $dateLabel . ' ' . $timestamp . '.pdf';
        $data = [
            'company_name' => 'PT. KASTRIA PRATAMA PERSADA ENERGY',
            'report_title' => 'Profit & Loss (Standard)',
            'report_period' => 'From 01 Jan 2024 to 31 Dec 2024',
            'report_period_date' => '01 Jan - 31 Dec 2024',
            'categories' => [
                [
                    'name' => 'OPERATING REVENUE',
                    'items' => [
                        ['name' => 'Pendapatan', 'value' => 258177117700],
                        ['name' => 'Pendapatan Korpel', 'value' => 51409223700],
                        ['name' => 'Pendapatan SDM', 'value' => 80560146600],
                        ['name' => 'Pendapatan Welsite', 'value' => 22031957900],
                        ['name' => 'Pendapatan Tiket', 'value' => 35919143500],
                        ['name' => 'Pendapatan Pengadaan Barang', 'value' => 107192399900],
                        ['name' => 'Pendapatan Tenaga Kerja Bukit Daun', 'value' => 43410375300],
                        ['name' => 'Pendapatan Survey', 'value' => 56231009000],
                        ['name' => 'Pendapatan Tenaga Kerja SCM', 'value' => 25345648700],
                    ]
                ],
                [
                    'name' => 'Total Cost of OPERATING REVENUE',
                    'value' => 123255050500
                ],
                [
                    'name' => 'Cost of Goods Sold',
                    'items' => [
                        ['name' => 'Total COGS', 'value' => 239049993150],
                        ['name' => 'HPP TK - Korpel', 'value' => 394617833600],
                        ['name' => 'HPP TK - SDM', 'value' => 7452557001],
                        ['name' => 'HPP TK - Website / PSD', 'value' => 205274608500],
                        ['name' => 'HPP Tiket', 'value' => 35074562202],
                        ['name' => 'HPP - BPJS', 'value' => 105472910100],
                        ['name' => 'HPP - PPh 21', 'value' => 30678363700],
                        ['name' => 'HPP Pengadaan Barang', 'value' => 94740288600],
                        ['name' => 'HPP Survey', 'value' => 54747185200],
                        ['name' => 'HPP TK - Bukit Daun', 'value' => 40296914900],
                        ['name' => 'HPP TK - SCM', 'value' => 123255050500],
                    ]
                ],
                [
                    'name' => 'Total Cost of Goods Sold',
                    'value' => 123255050500
                ],
                [
                    'name' => 'Net Profit/Loss',
                    'items' => [
                        ['name' => 'NET PROFIT/LOSS (Before Tax)', 'value' => 335395413427],
                        ['name' => 'NET PROFIT/LOSS (After Tax)', 'value' => 335395413427],
                    ]
                ],
            ]
        ];
        $pdf = SnappyPdf::loadView('pdf.reports-general', [
            'data' => $data,
        ])
            ->setOption('page-size', 'A4')
            ->setOption('margin-top', '10mm')
            ->setOption('margin-bottom', '10mm')
            ->setOption('margin-left', '15mm')
            ->setOption('margin-right', '15mm');
        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->inline();
        }, $filename);
    }
}
