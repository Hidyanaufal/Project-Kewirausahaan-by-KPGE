<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\EnhancedReport;
use App\Contracts\ExportableReport;
use App\DTO\ReportDTO;
use App\Filament\Company\Pages\Concerns\HasReportTabs;
use App\Helpers\AuthHelper;
use App\Models\Company;
use App\Services\CompanySettingsService;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\Column;
use App\Transformers\CashFlowStatementReportTransformer;
use App\Utilities\Currency\CurrencyConverter;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\MaxWidth;
use Guava\FilamentClusters\Forms\Cluster;
use Illuminate\Support\Collection;
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
        return $this->reportService->buildCashFlowStatementReport(
            $this->getFormattedStartDate(),
            $this->getFormattedEndDate(),
            $columns,
            projectId: null,
            companyId: $this->company->id
        );
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

    /**
     * @return array<string, mixed>
     */
    private function cashFlowExportMeta(string $balanceColumn, int $descriptionColumnIndex = 1): array
    {
        $balanceColumnIndex = ord(strtoupper($balanceColumn)) - ord('A');

        $widths = match ($balanceColumnIndex) {
            3 => ['14%', '12%', '44%', '30%'],
            2 => ['14%', '46%', '40%'],
            1 => ['58%', '42%'],
            default => ['14%', '46%', '40%'],
        };

        return [
            'amount_decimal_places' => 0,
            'number_format_code' => '#,##0',
            'numeric_columns' => [$balanceColumn],
            'description_column_index' => $descriptionColumnIndex,
            'balance_column_index' => $balanceColumnIndex,
            'pdf_table' => [
                'column_widths' => $widths,
            ],
        ];
    }

    /**
     * @param  array<int, float|int|string>  $data
     * @return array{data: array<int, float|int|string>, style?: array<string, string>, cell_styles?: array<int, array<string, string>>}
     */
    private function cashFlowMakeRow(array $data, array $style = [], int $padLevel = 0, int $descriptionColumnIndex = 0): array
    {
        $row = ['data' => $data];

        if ($style !== []) {
            $row['style'] = $style;
        }

        if ($padLevel > 0) {
            $row['cell_styles'] = [
                $descriptionColumnIndex => [
                    'padding-left' => ($padLevel * 10) . 'px',
                ],
            ];
        }

        return $row;
    }

    private function parseCashFlowAmount(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $currency = CompanySettingsService::getDefaultCurrency($this->company->id);

        return CurrencyConverter::convertToFloat((string) $value, $currency);
    }

    private function getCashFlowReportDto(): ReportDTO
    {
        return $this->reportService->buildCashFlowStatementReport(
            $this->getFormattedStartDate(),
            $this->getFormattedEndDate(),
            $this->getToggledColumns(),
            companyId: $this->company->id,
        );
    }

    /**
     * @return array{starting: float, net: float, ending: float}
     */
    private function getCashFlowFooterTotals(ReportDTO $reportDto): array
    {
        $overall = $reportDto->overallTotal;

        return [
            'starting' => $this->parseCashFlowAmount($overall?->startingBalance),
            'net' => $this->parseCashFlowAmount($overall?->netMovement),
            'ending' => $this->parseCashFlowAmount($overall?->endingBalance),
        ];
    }

    private function getCashFlowSectionTotal(ReportDTO $reportDto, string $sectionName): float
    {
        $category = $reportDto->categories[$sectionName] ?? null;

        if ($category === null) {
            return 0.0;
        }

        return $this->parseCashFlowAmount($category->summary->netMovement ?? 0);
    }

    /**
     * @return array{0: string, 1: string, 2: float|string}
     */
    private function cashFlowDetailRow(?string $accountCode, string $label, float | string $balance = ''): array
    {
        $codeCell = ($accountCode !== null && $accountCode !== '') ? '__str' . $accountCode : '';

        return [$codeCell, $label, $balance];
    }

    /**
     * Baris section untuk export Direct / Detail.
     *
     * - direct: daftar akun per section + total section + footer (tanpa subtotal per tipe akun)
     * - detail: seperti laporan on-screen — subtotal tipe hanya jika >1 akun dalam tipe tersebut
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function buildCashFlowSectionRows(
        ReportDTO $reportDto,
        int $descriptionColumnIndex,
        string $exportTemplate = 'detail',
    ): Collection {
        $rows = collect();
        $footerStyle = ['font-weight' => 'bold', 'color' => 'rgb(0,0,128)'];
        $includeTypeTotals = $exportTemplate === 'detail';

        $sections = [
            'Operating Activities' => 'Total of Operating Activities',
            'Investing Activities' => 'Total of Investing',
            'Financing Activities' => 'Total of Financing',
        ];

        foreach ($sections as $sectionKey => $totalLabel) {
            $category = $reportDto->categories[$sectionKey] ?? null;
            $sectionTotal = $this->getCashFlowSectionTotal($reportDto, $sectionKey);

            $rows->push($this->cashFlowMakeRow(
                $this->cashFlowDetailRow(null, $sectionKey, ''),
                ['font-weight' => 'bold'],
                0,
                $descriptionColumnIndex,
            ));

            if ($category !== null) {
                foreach ($category->types ?? [] as $typeName => $type) {
                    $accountRows = [];
                    foreach ($type->accounts ?? [] as $account) {
                        $amount = $this->parseCashFlowAmount($account->balance->netMovement ?? 0);
                        if ($amount == 0.0) {
                            continue;
                        }

                        $accountRows[] = $account;
                    }

                    if ($accountRows === []) {
                        continue;
                    }

                    $rows->push($this->cashFlowMakeRow(
                        $this->cashFlowDetailRow(null, $typeName, ''),
                        ['font-weight' => 'bold'],
                        1,
                        $descriptionColumnIndex,
                    ));

                    foreach ($accountRows as $account) {
                        $amount = $this->parseCashFlowAmount($account->balance->netMovement ?? 0);
                        $rows->push($this->cashFlowMakeRow(
                            $this->cashFlowDetailRow($account->accountCode, $account->accountName, $amount),
                            [],
                            2,
                            $descriptionColumnIndex,
                        ));
                    }

                    if ($includeTypeTotals && count($accountRows) > 1) {
                        $typeTotal = $this->parseCashFlowAmount($type->summary->netMovement ?? 0);
                        $rows->push($this->cashFlowMakeRow(
                            $this->cashFlowDetailRow(null, 'Total of ' . $typeName, $typeTotal),
                            ['font-weight' => 'bold'],
                            1,
                            $descriptionColumnIndex,
                        ));
                    }
                }
            }

            $rows->push($this->cashFlowMakeRow(
                $this->cashFlowDetailRow(null, $totalLabel, $sectionTotal),
                $footerStyle,
                0,
                $descriptionColumnIndex,
            ));
        }

        $this->appendCashFlowGrandFooter($rows, $reportDto, 'detail', $descriptionColumnIndex);

        return $rows;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: float|string}
     */
    private function cashFlowSummaryRow(?string $accountCode, string $status, string $accountType, float | string $balance = ''): array
    {
        $codeCell = ($accountCode !== null && $accountCode !== '') ? '__str' . $accountCode : '';

        return [$codeCell, $status, $accountType, $balance];
    }

    private function appendCashFlowGrandFooter(
        Collection $rows,
        ReportDTO $reportDto,
        string $layout,
        int $descriptionColumnIndex,
    ): void {
        $footer = $this->getCashFlowFooterTotals($reportDto);
        $footerStyle = [
            'font-weight' => 'bold',
            'color' => $layout === 'summary' ? 'blue' : 'rgb(0, 0, 128)',
        ];

        $footerRows = [
            ['', 'Total of Net Cash Provide (Used) in This Period', $footer['net']],
            ['', 'Total of Cash & Cash Equivalent at Beginning of Period', $footer['starting']],
            ['', 'Total of Cash & Cash Equivalent at End of Period', $footer['ending']],
        ];

        foreach ($footerRows as [$code, $label, $amount]) {
            if ($layout === 'summary') {
                $rows->push([
                    'data' => $this->cashFlowSummaryRow($code ?: null, '', $label, $amount),
                    'style' => $footerStyle,
                ]);
            } else {
                $rows->push([
                    'data' => $this->cashFlowDetailRow($code ?: null, $label, $amount),
                    'style' => $footerStyle,
                ]);
            }
        }
    }

    public function __buildReportDirect(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $reportDto = $this->getCashFlowReportDto();
        $now = now()->format('Y-m-d_H-i-s');
        $descCol = 1;

        $data = [
            'filename' => "{$company->name} Statement of Cash Flows (Direct Method) {$now}.pdf",
            'company_name' => $company->name,
            'report_title' => 'Statement of Cash Flows (Direct Method)',
            'report_period' => 'Period ' . $reportDto->startDate?->format('F Y') . ' to ' . $reportDto->endDate?->format('F Y'),
            'columns' => [
                'Account No.',
                'Description',
                'Balance',
            ],
            'rows' => $this->buildCashFlowSectionRows($reportDto, $descCol, 'direct')->toArray(),
        ];

        return array_merge($data, $this->cashFlowExportMeta('C', $descCol));
    }

    // [Template]
    public function __buildReportSummaryTemplate(): array
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

    public function __buildReportSummary(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $reportDto = $this->getCashFlowReportDto();
        $start = $reportDto->startDate;
        $end = $reportDto->endDate;

        $data = [
            'filename' => 'Statement of Cash Flows Summary.pdf',
            'company_name' => strtoupper($company->name),
            'report_title' => 'Statement of Cash Flows Summary (Indirect Method)',
            'report_period' => 'Period ' . Carbon::parse($start)->format('F Y') . ' to ' . Carbon::parse($end)->format('F Y'),
            'columns' => ['Account No.', 'Status', 'Account Type', 'Balance'],
            'rows' => [],
        ];

        $rows = collect();
        $descCol = 2;

        $sections = [
            'Operating Activities' => ['header' => 'Operating Activities', 'totalLabel' => 'Total of Operating Activities'],
            'Investing Activities' => ['header' => 'Investing', 'totalLabel' => 'Total of Investing'],
            'Financing Activities' => ['header' => 'Financing', 'totalLabel' => 'Total of Financing'],
        ];

        foreach ($sections as $sectionKey => $labels) {
            $category = $reportDto->categories[$sectionKey] ?? null;

            $rows->push([
                'data' => $this->cashFlowSummaryRow(null, '', $labels['header'], ''),
                'style' => ['font-weight' => 'bold'],
            ]);

            if ($category !== null) {
                foreach ($category->types ?? [] as $type) {
                    foreach ($type->accounts ?? [] as $account) {
                        $amount = $this->parseCashFlowAmount($account->balance->netMovement ?? 0);
                        if ($amount == 0.0) {
                            continue;
                        }

                        $status = $amount >= 0 ? 'Added' : 'Deduct';
                        $rows->push($this->cashFlowMakeRow(
                            $this->cashFlowSummaryRow(
                                $account->accountCode,
                                $status,
                                $account->accountName,
                                $amount,
                            ),
                            ['color' => $amount < 0 ? 'red' : 'black'],
                            1,
                            $descCol,
                        ));
                    }
                }
            }

            $sectionTotal = $this->getCashFlowSectionTotal($reportDto, $sectionKey);
            $rows->push([
                'data' => $this->cashFlowSummaryRow(null, '', $labels['totalLabel'], $sectionTotal),
                'style' => ['font-weight' => 'bold', 'color' => 'blue'],
            ]);
        }

        $this->appendCashFlowGrandFooter($rows, $reportDto, 'summary', $descCol);

        $data['rows'] = $rows->toArray();

        return array_merge($data, $this->cashFlowExportMeta('D', 2));
    }

    // [Template]
    public function __buildReportIndirectTemplate(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $report = $this->report;
        $now = now()->format('Y-m-d_H-i-s');

        $data = [
            'filename' => "{$company->name} Statement of Cash Flows Detail (Indirect Method) {$now}.pdf",
            'company_name' => $company->name,
            'report_title' => 'Statement of Cash Flows Detail (Indirect Method)',
            'report_period' => 'Period ' . Carbon::parse($report->getStartDate())->format('F Y') . ' to ' . Carbon::parse($report->getEndDate())->format('F Y'),
            'columns' => [
                'Account No.',
                'Account Name',
                'Balance',
            ],
            'rows' => [],
        ];

        // helper padding level
        $pad = fn (int $level = 0) => ['padding-left' => ($level * 10) . 'px'];

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

        $rows->push(['data' => ['2003', 'Hutang KPG', 1760000000.00], 'style' => $pad(3)]);
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
        $rows->push(['data' => ['2100.03', 'Biaya yang masih harus dibayar', 592772439.00], 'style' => $pad(3)]);

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

        $rows->push(['data' => ['2002', 'Hutang KPP', -955000000.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['2004', 'PPn Keluaran', -87280396.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['2005', 'Hutang PPh Badan', -192137101.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-011', 'Piutang Pengadaan Barang', -856181763.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-005', 'Piutang Tenaga Kerja Korpel', -299109898.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1103-017', 'Piutang Survey', -188331534.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1107-001', 'Sewa Gedung Dibayar Dimuka', -3340000.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['1107-004', 'Prepaid PPh 23', -1.26], 'style' => $pad(3)]);
        $rows->push(['data' => ['1107-006', 'PPh Pasal 28', -436968228.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['2100.01', 'BPJS yang masih harus dibayar', -46475928.00], 'style' => $pad(3)]);
        $rows->push(['data' => ['2100.02', 'PPh 21 yang masih harus dibayar', -2874373.00], 'style' => $pad(3)]);

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

    public function __buildReportIndirect(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $reportDto = $this->getCashFlowReportDto();
        $start = $reportDto->startDate;
        $end = $reportDto->endDate;
        $now = now()->format('Y-m-d_H-i-s');
        $descCol = 1;

        $data = [
            'filename' => "{$company->name} Statement of Cash Flows Detail (Indirect Method) {$now}.pdf",
            'company_name' => $company->name,
            'report_title' => 'Statement of Cash Flows Detail (Indirect Method)',
            'report_period' => 'Period ' . Carbon::parse($start)->format('F Y') . ' to ' . Carbon::parse($end)->format('F Y'),
            'columns' => ['Account No.', 'Account Name', 'Balance'],
            'rows' => $this->buildCashFlowSectionRows($reportDto, $descCol, 'detail')->toArray(),
        ];

        return array_merge($data, $this->cashFlowExportMeta('C', $descCol));
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
                            'detail' => 'Cashflow Detail (Indirect Method)',
                            'direct' => 'Cashflow Direct Method',
                            'summary' => 'Cashflow Summary',
                        ])
                        ->required(),
                ])
                ->action(function (array $data) {
                    $format = $data['format'];
                    $template = $data['template'];

                    if ($format == 'xlsx') {
                        return $this->exportXLSX($template);
                    }

                    if ($format == 'pdf') {
                        return $this->exportPDF($template);
                    }
                }),
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
