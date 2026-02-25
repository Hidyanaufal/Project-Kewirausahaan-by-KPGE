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
                            $flattened = array_map(function ($value) {
                                return (is_array($value) && array_key_exists('name', $value))
                                    ? $value['name']
                                    : $value;
                            }, $row);
                            $rows[] = array_values($flattened);
                        }
                    }
                }

                return $rows;
            }
        };

        return Excel::download($export, $filename);
    }

    public function exportToPdfEnhanced(array $data): StreamedResponse
    {
        $pdf = SnappyPdf::loadView('pdf.reports-general', [
            'data' => $data,
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
        }, $data['filename']);
    }

    public function exportToExcelEnhanced(array $data): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $filename = str_replace('.pdf', '.xlsx', $data['filename']);
        $export = new class($data) implements
            \Maatwebsite\Excel\Concerns\FromArray,
            \Maatwebsite\Excel\Concerns\WithStyles,
            \Maatwebsite\Excel\Concerns\WithEvents,
            \Maatwebsite\Excel\Concerns\WithTitle
        {
            use \Maatwebsite\Excel\Concerns\RegistersEventListeners;

            public function __construct(protected array $data) {}

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
                    $rows[] = str_replace('__str', '', $row['data']);
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
                $sheet->getStyle("A4:" . $highestCol . "4")->applyFromArray([
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
                $tableRange = "A4:" . $highestCol . $highestRow;
                $sheet->getStyle($tableRange)->getBorders()->getAllBorders()
                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

                // ====== FORMAT ANGKA ======
                foreach (range('B', $highestCol) as $col) {
                    $sheet->getStyle("{$col}5:{$col}{$highestRow}")
                        ->getNumberFormat()->setFormatCode('#,##0.00');
                    $sheet->getStyle("{$col}5:{$col}{$highestRow}")
                        ->getAlignment()->setHorizontal('right');
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

            public static function afterSheet(\Maatwebsite\Excel\Events\AfterSheet $event)
            {
                // Freeze di bawah header tabel
                $event->sheet->freezePane('A5');
            }

            public function title(): string
            {
                return $this->data['report_title'] ?? 'Report';
            }
        };

        return \Maatwebsite\Excel\Facades\Excel::download($export, $filename);
    }
}
