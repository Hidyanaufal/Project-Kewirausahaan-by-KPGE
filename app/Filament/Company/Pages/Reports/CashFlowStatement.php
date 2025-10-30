<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\EnhancedReport;
use App\Contracts\ExportableReport;
use App\DTO\ReportDTO;
use App\Filament\Company\Pages\Concerns\HasReportTabs;
use App\Helpers\AuthHelper;
use App\Models\Company;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\Column;
use App\Transformers\CashFlowStatementReportTransformer;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Form;
use Filament\Forms;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\MaxWidth;
use Guava\FilamentClusters\Forms\Cluster;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashFlowStatement extends BaseReportPage implements EnhancedReport
{
    use HasReportTabs;

    protected static string $view = 'filament.company.pages.reports.cash-flow-statement';

    protected ReportService $reportService;

    protected ExportService $exportService;

    public function boot(ReportService $reportService, ExportService $exportService): void
    {
        $this->reportService = $reportService;
        $this->exportService = $exportService;
    }

    public function getTable(): array
    {
        return [
            Column::make('account_code')
                ->label('ACCOUNT CODE')
                ->alignment(Alignment::Left),
            Column::make('account_name')
                ->label('CASH INFLOWS AND OUTFLOWS')
                ->alignment(Alignment::Left),
            Column::make('net_movement')
                ->label($this->getDisplayDateRange())
                ->alignment(Alignment::Right),
        ];
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->inlineLabel(false)
            ->columns(4)
            ->schema([
                $this->getDateRangeFormComponent(),
                Cluster::make([
                    $this->getStartDateFormComponent(),
                    $this->getEndDateFormComponent(),
                ])->extraFieldWrapperAttributes([
                    'class' => 'report-hidden-label',
                ]),
            ]);
    }

    protected function buildReport(array $columns): ReportDTO
    {
        return $this->reportService->buildCashFlowStatementReport($this->getFormattedStartDate(), $this->getFormattedEndDate(), $columns);
    }

    protected function getTransformer(ReportDTO $reportDTO): ExportableReport
    {
        return new CashFlowStatementReportTransformer($reportDTO);
    }

    public function buildReportEnhanced(
        ?string $template = null,
        string $format = 'pdf',
    ): array {
        if ($template == 'detail') {
            return $this->__buildReportIndirect();
        }
        if ($template == 'summary') {
            return $this->__buildReportSummary();
        }
        return $this->__buildReportDirect();
    }

    public function __buildReportDirect(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $report = $this->report;
        $now = now()->format('Y-m-d_H-i-s');

        $data = [
            'filename'      => "{$company->name} Statement of Cash Flows (Direct Method) {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => 'Statement of Cash Flows (Direct Method)',
            'report_period' => "Period " . Carbon::parse($report->getStartDate())->format('F Y') . " to " . Carbon::parse($report->getEndDate())->format('F Y'),
            'columns'       => [
                "Description",
                "Balance",
            ],
            'rows' => [],
        ];

        // Helper padding function
        $pad = fn(int $level = 0) => ['padding-left' => ($level * 10) . 'px'];

        $rows = collect();

        // === Cash Flow from Operating Activities ===
        $rows->push([
            'data' => ['Cash Flows from Operating Activities'],
            'style' => array_merge(['font-weight' => 'bold'], $pad(0)),
        ]);

        $rows->push(['data' => ['Cash from Sales', 2419141494.74], 'style' => $pad(1)]);
        $rows->push(['data' => ['PENDAPATAN DILUAR USAHA', null], 'style' => array_merge(['font-weight' => 'bold'], $pad(1))]);
        $rows->push(['data' => ['Pendapatan Lain-Lain', 5343645.82], 'style' => $pad(2)]);

        $rows->push(['data' => ['COGS', -2428410027.00], 'style' => array_merge(['font-weight' => 'bold'], $pad(1))]);
        $rows->push(['data' => ['HPP TK - Korpel', -3945019968.00], 'style' => $pad(2)]);
        $rows->push(['data' => ['HPP TK - SDM', -745755701.00], 'style' => $pad(2)]);
        $rows->push(['data' => ['HPP TK - Website / PSD', -2052746085.00], 'style' => $pad(2)]);
        $rows->push(['data' => ['HPP Tiket', -350745622.00], 'style' => $pad(2)]);
        $rows->push(['data' => ['HPP - BPJS', -1101040938.00], 'style' => $pad(2)]);
        $rows->push(['data' => ['HPP - PPh 21', -331971010.00], 'style' => $pad(2)]);
        $rows->push(['data' => ['HPP Pengadaan Barang', -975570647.00], 'style' => $pad(2)]);
        $rows->push(['data' => ['HPP Survey', -5474071852.00], 'style' => $pad(2)]);
        $rows->push(['data' => ['HPP TK - Bukit Daun', -402969149.00], 'style' => $pad(2)]);
        $rows->push(['data' => ['HPP TK - SCM', -123255055.00], 'style' => $pad(2)]);

        // === Operating Profit / Loss ===
        $rows->push([
            'data' => ['Operating Profit/(Loss) before changes in operating assets and liabilities', -814439211.17],
            'style' => array_merge(['font-weight' => 'bold'], $pad(1)),
        ]);

        // === Decrease (Increase) in operating assets ===
        $rows->push(['data' => ['Decrease (increase) in operating assets'], 'style' => array_merge(['font-weight' => 'bold'], $pad(1))]);
        $rows->push(['data' => ['Biaya dibayar dimuka', -192137100.00], 'style' => $pad(2)]);
        $rows->push(['data' => ['Angsuran PPh 25', -192137100.00], 'style' => $pad(2)]);
        $rows->push(['data' => ['Total Decrease (increase) in operating assets', -192137100.00], 'style' => array_merge(['font-weight' => 'bold'], $pad(1))]);

        // === Increase (decrease) in operating liabilities ===
        $rows->push(['data' => ['Increase (decrease) in operating liabilities'], 'style' => array_merge(['font-weight' => 'bold'], $pad(1))]);
        $rows->push(['data' => ['Hutang KPP', -955000000.00], 'style' => $pad(2)]);
        $rows->push(['data' => ['Hutang KPG', 1760000000.00], 'style' => $pad(2)]);
        $rows->push(['data' => ['PPN Keluaran', -142922929.00], 'style' => $pad(2)]);
        $rows->push(['data' => ['Total Increase (decrease) in operating liabilities', 662077071.00], 'style' => array_merge(['font-weight' => 'bold'], $pad(1))]);

        // === Net Cash Used/Provided ===
        $rows->push([
            'data' => ['Net Cash (used in) / Provided by Operating Activities', -344499240.17],
            'style' => array_merge(['font-weight' => 'bold'], $pad(0)),
        ]);

        // === Cash Flows from Investing ===
        $rows->push(['data' => ['Cash Flows from Investing Activities'], 'style' => array_merge(['font-weight' => 'bold'], $pad(0))]);
        $rows->push(['data' => ['Net cash provided by / (used in) investing activities', 0], 'style' => array_merge(['font-weight' => 'bold'], $pad(0))]);

        // === Cash Flows from Financing ===
        $rows->push(['data' => ['Cash Flows from Financing Activities'], 'style' => array_merge(['font-weight' => 'bold'], $pad(0))]);
        $rows->push(['data' => ['Net cash provided by / (used in) financing activities', 0], 'style' => array_merge(['font-weight' => 'bold'], $pad(0))]);

        // === Total Cash ===
        $rows->push(['data' => ['Net Cash provided by / (used in) this period', -344499240.17], 'style' => array_merge(['font-weight' => 'bold', 'color' => 'rgb(0, 0, 128)'], $pad(0))]);
        $rows->push(['data' => ['Cash & equivalent at Beginning of Period', 358051327.93], 'style' => array_merge(['font-weight' => 'bold', 'color' => 'rgb(0, 0, 128)'], $pad(0))]);
        $rows->push(['data' => ['Cash & equivalent at End of Period', 13552087.76], 'style' => array_merge(['font-weight' => 'bold', 'color' => 'rgb(0, 0, 128)'], $pad(0))]);

        $data['rows'] = $rows->toArray();

        return $data;
    }

    public function __buildReportSummary(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $start = $this->filters['start_date'] ?? now()->startOfYear();
        $end = $this->filters['end_date'] ?? now()->endOfYear();

        $data = [
            'filename' => 'Statement of Cash Flows Summary.pdf',
            'company_name' => strtoupper($company->name),
            'report_title' => 'Statement of Cash Flows Summary (Indirect Method)',
            'report_period' => 'Period ' . $start->format('F Y') . ' to ' . $end->format('F Y'),
            'columns' => ['Status', 'Account Type', 'Balance'],
            'rows' => [],
        ];

        $rows = collect();

        /** ==============================
         *  Operating Activities
         * ============================== */
        $rows->push([
            'data' => ['Operating Activities', '', ''],
            'style' => ['font-weight' => 'bold'],
        ]);

        $operating = [
            ['Net Income', 'Net Income', 329911653.27],
            ['Added', 'Account Receivable', 1667110129.82],
            ['Added', 'Inventory', 0],
            ['Added', 'Other Current Asset', 55921604],
            ['Added', 'Accumulated Depreciation', 33740750],
            ['Added', 'Account Payable', 0],
            ['Added', 'Other Current Liability', 2352772439],
            ['Deduct', 'Account Receivable', -3037566195],
            ['Deduct', 'Other Current Asset', -440308823.26],
            ['Deduct', 'Net Income', -1306080798],
        ];

        foreach ($operating as [$status, $account, $balance]) {
            $rows->push([
                'data' => [$status, $account, number_format($balance, 2, ',', '.')],
                'style' => ['padding-left' => '20px', 'color' => $balance < 0 ? 'red' : 'black'],
            ]);
        }

        $rows->push([
            'data' => ['Total of Operating Activities', '', number_format(-344499240.17, 2, ',', '.')],
            'style' => ['font-weight' => 'bold', 'color' => 'blue'],
        ]);

        /** ==============================
         *  Investing
         * ============================== */
        $rows->push(['data' => ['Investing', '', ''], 'style' => ['font-weight' => 'bold']]);
        $rows->push(['data' => ['Added', 'Fixed Asset', '0,00'], 'style' => ['padding-left' => '20px']]);
        $rows->push(['data' => ['Deduct', 'Fixed Asset', '0,00'], 'style' => ['padding-left' => '20px']]);
        $rows->push(['data' => ['Total of Investing', '', '0,00'], 'style' => ['font-weight' => 'bold', 'color' => 'blue']]);

        /** ==============================
         *  Financing
         * ============================== */
        $rows->push(['data' => ['Financing', '', ''], 'style' => ['font-weight' => 'bold']]);
        $rows->push(['data' => ['Added', 'Equity', '0,00'], 'style' => ['padding-left' => '20px']]);
        $rows->push(['data' => ['Deduct', 'Equity', '0,00'], 'style' => ['padding-left' => '20px']]);
        $rows->push(['data' => ['Total of Financing', '', '0,00'], 'style' => ['font-weight' => 'bold', 'color' => 'blue']]);

        /** ==============================
         *  Summary
         * ============================== */
        $rows->push(['data' => ['Total of Net Cash Provide (Used) in This Period', '', '-344.499.240,17'], 'style' => ['font-weight' => 'bold', 'color' => 'blue']]);
        $rows->push(['data' => ['Total of Cash & Cash Equivalent at Beginning of Period', '', '358.051.327,93'], 'style' => ['font-weight' => 'bold', 'color' => 'blue']]);
        $rows->push(['data' => ['Total of Cash & Cash Equivalent at End of Period', '', '13.552.087,76'], 'style' => ['font-weight' => 'bold', 'color' => 'blue']]);

        $data['rows'] = $rows->toArray();
        return $data;
    }

    public function __buildReportIndirect(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $report = $this->report;
        $now = now()->format('Y-m-d_H-i-s');

        $data = [
            'filename'      => "{$company->name} Statement of Cash Flows Detail (Indirect Method) {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => 'Statement of Cash Flows Detail (Indirect Method)',
            'report_period' => "Period " . Carbon::parse($report->getStartDate())->format('F Y') . " to " . Carbon::parse($report->getEndDate())->format('F Y'),
            'columns'       => [
                'Account No.',
                'Account Name',
                'Balance',
            ],
            'rows' => [],
        ];

        // helper padding level
        $pad = fn(int $level = 0) => ['padding-left' => ($level * 10) . 'px'];

        $rows = collect();

        // ===== Operating Activities =====
        $rows->push([
            'data' => ['Operating Activities', '', ''],
            'style' => array_merge(['font-weight' => 'bold'], $pad(0)),
        ]);

        // Net Income
        $rows->push([
            'data' => ['', 'Net Income (From Profit & Loss Statement)', 329911653.27],
            'style' => $pad(1),
        ]);

        // Added Section
        $rows->push([
            'data' => ['', 'Added', ''],
            'style' => array_merge(['font-weight' => 'bold'], $pad(1)),
        ]);

        // Accumulated Depreciation
        $rows->push([
            'data' => ['', 'Accumulated Depreciation', ''],
            'style' => array_merge(['font-weight' => 'bold'], $pad(2)),
        ]);

        $rows->push(['data' => ['1200-001', 'Akumulasi Penyusutan Gedung', 15900750.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1200-002', 'Akumulasi Penyusutan Kendaraan', 17840000.00], 'style' => $pad(3)]);

        $rows->push([
            'data' => ['', 'Total of Accumulated Depreciation', 33740750.00],
            'style' => array_merge(['font-weight' => 'bold'], $pad(2)),
        ]);

        // Except Depreciation (Added)
        $rows->push([
            'data' => ['', 'Except Depreciation', ''],
            'style' => array_merge(['font-weight' => 'bold'], $pad(2)),
        ]);

        $rows->push(['data' => ['200-3', 'Hutang KPG', 1760000000.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1108-6', 'Aset lain-lain - piutang pajak estimasi pengemba', 52581603.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1108-8', 'Aset lain-lain - Deposit Sewa Kantor', 3340000.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-021', 'Piutang Tenaga Kerja EE', 210521712.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-022', 'Penyisihan Piutang Tidak Tertagih', 11506862.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-013', 'Piutang Tenaga Kerja Karaba Bodas', 71971827.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-004', 'Piutang Tenaga Kerja Wellsite', 150869092.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-015', 'Piutang Pekerjaan Sipil', 58426995.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-006', 'Piutang Tenaga Kerja SDM', 550451283.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-016', 'Piutang Tenaga Kerja Bukit Daun', 97915031.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-018', 'Piutang Tenaga Kerja DE', 228432463.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-009', 'Piutang Tiket', 115866595.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-019', 'Piutang Tenaga Kerja SCM', 171867109.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1107-005', 'Angsuran PPh 25', 1.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['2100-03', 'Biaya yang masih harus dibayar', 592772439.00], 'style' => $pad(3)]);

        $rows->push([
            'data' => ['', 'Total of Except Depreciation', 4075804172.82],
            'style' => array_merge(['font-weight' => 'bold'], $pad(2)),
        ]);

        $rows->push([
            'data' => ['', 'Total of Added', 4109549222.82],
            'style' => array_merge(['font-weight' => 'bold'], $pad(1)),
        ]);

        // Deduct Section
        $rows->push([
            'data' => ['', 'Deduct', ''],
            'style' => array_merge(['font-weight' => 'bold'], $pad(1)),
        ]);

        $rows->push([
            'data' => ['', 'Except Depreciation', ''],
            'style' => array_merge(['font-weight' => 'bold'], $pad(2)),
        ]);

        $rows->push(['data' => ['200-2', 'Hutang KPP', -955000000.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['200-4', 'PPn Keluaran', -87280396.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['200-5', 'Hutang PPh Badan', -192137101.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-011', 'Piutang Pengadaan Barang', -856181763.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-005', 'Piutang Tenaga Kerja Korpel', -299109898.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-017', 'Piutang Survey', -188331534.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1107-001', 'Sewa Gedung Dibayar Dimuka', -3340000.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1107-004', 'Prepaid PPh 23', -1.26], 'style' => $pad(3)]);
        $rows->push(['data' => ['1107-006', 'PPh Pasal 28', -436968228.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['2100-01', 'BPJS yang masih harus dibayar', -46475928.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['2100-02', 'PPh 21 yang masih harus dibayar', -2874373.00], 'style' => $pad(3)]);

        $rows->push([
            'data' => ['', 'Total of Except Depreciation', -4783985316.26],
            'style' => array_merge(['font-weight' => 'bold'], $pad(2)),
        ]);

        $rows->push([
            'data' => ['', 'Total of Deduct', -4783985316.26],
            'style' => array_merge(['font-weight' => 'bold'], $pad(1)),
        ]);

        // Total Operating
        $rows->push([
            'data' => ['', 'Total of Operating Activities', -344499240.17],
            'style' => array_merge(['font-weight' => 'bold', 'color' => 'rgb(0,0,128)'], $pad(0)),
        ]);

        // Net Cash Movement
        $rows->push([
            'data' => ['', 'Total of Net Cash Provide (Used) in This Period', -344499240.17],
            'style' => array_merge(['font-weight' => 'bold', 'color' => 'rgb(0,0,128)'], $pad(0)),
        ]);
        $rows->push([
            'data' => ['', 'Total of Cash & Cash Equivalent at Beginning of Period', 358051327.93],
            'style' => array_merge(['font-weight' => 'bold', 'color' => 'rgb(0,0,128)'], $pad(0)),
        ]);
        $rows->push([
            'data' => ['', 'Total of Cash & Cash Equivalent at End of Period', 13552087.76],
            'style' => array_merge(['font-weight' => 'bold', 'color' => 'rgb(0,0,128)'], $pad(0)),
        ]);

        $data['rows'] = $rows->toArray();

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export')
                ->button()
                ->outlined()
                ->icon('heroicon-m-chevron-down')
                ->iconPosition(IconPosition::After)
                ->slideOver()
                ->modalWidth(MaxWidth::Small)
                ->form([
                    Forms\Components\Select::make('format')
                        ->options([
                            'pdf' => 'PDF',
                            'xlsx' => 'XLSX',
                        ])
                        ->default('pdf')
                        ->required(),
                    Forms\Components\Select::make('template')
                        ->options([
                            'detail' => 'Cashflow Detail',
                            'direct' => 'Cashflow Direct Method',
                            'summary' => 'Cashflow Summary',
                        ])
                        ->required(),
                ])
                ->action(function (array $data) {
                    $format     = $data['format'];
                    $template   = $data['template'];

                    if ($format == 'xlsx') {
                        return $this->exportXLSX($template);
                    }

                    if ($format == 'pdf') {
                        return $this->exportPDF($template);
                    }
                })
        ];
    }

    public function exportCSV(): StreamedResponse
    {
        return $this->exportService->exportToCsv($this->company, $this->report, $this->getFilterState('startDate'), $this->getFilterState('endDate'), $this->getActiveTab());
    }

    public function exportPDF($template = null): StreamedResponse
    {
        return $this->exportService->exportToPdfEnhanced(
            data: $this->buildReportEnhanced(
                template: $template,
                format: 'pdf'
            )
        );
    }

    public function exportXLSX($template = null): BinaryFileResponse
    {
        return $this->exportService->exportToExcelEnhanced(
            data: $this->buildReportEnhanced(
                template: $template,
                format: 'xlsx'
            )
        );
    }
}
