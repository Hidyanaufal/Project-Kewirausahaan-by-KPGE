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
            return $this->__buildReportDetail();
        }
        if ($template == 'summary') {
            return $this->__buildReportSummary();
        }
        return $this->__buildReportDefault();
    }

    public function __buildReportDefault(): array
    {
        return [];
    }

    public function __buildReportSummary(): array
    {
        return [];
    }

    public function __buildReportDetail(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $report = $this->report;
        $now = now()->format('Y-m-d_H-i-s');

        $toNumber = fn($v) => is_null($v)
            ? 0
            : (float) str_replace(',', '.', preg_replace('/[^\d]/', '', $v));

        $data = [
            'filename'      => "{$company->name} Statement of Cash Flows Detail {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => 'Statement of Cash Flows Detail (Indirect Method)',
            'report_period' => "As of " . Carbon::parse($report->getStartDate())->format('d M Y'),
            'columns'       => [
                "Account No.",
                "Account Name",
                "Balance",
            ],
            'rows' => [],
        ];

        $rows = collect();

        foreach ($report->getCategories() as $categoryName => $categoryDTO) {
            // === Level 1: Category Header ===
            $rows->push([
                'data' => [$categoryName, null, null],
                'style' => ['font-weight' => 'bold'],
            ]);

            // === Level 2: Iterate Types (e.g. Equity, Liability, etc.) ===
            if (!empty($categoryDTO->types)) {
                foreach ($categoryDTO->types as $typeName => $typeDTO) {
                    // Sub-header (e.g., "Equity")
                    $rows->push([
                        'data' => [$typeName, null, null],
                        'style' => ['font-weight' => 'bold', 'padding-left' => '10px'],
                    ]);

                    // Data rows for this type
                    foreach ($typeDTO->data as $item) {
                        $accountCode = $item['account_code'] ?? '';
                        $accountName = is_array($item['account_name'])
                            ? ($item['account_name']['name'] ?? '')
                            : $item['account_name'];
                        $netMovement = $toNumber($item['net_movement'] ?? 0);

                        $rows->push([
                            'data' => [
                                '__str' . $accountCode,
                                $accountName,
                                $netMovement,
                            ],
                            'style' => ['padding-left' => '20px'],
                        ]);
                    }

                    // Subtotal per type
                    $rows->push([
                        'data' => [
                            '',
                            $typeDTO->summary['account_name'] ?? 'Subtotal ' . $typeName,
                            $toNumber($typeDTO->summary['net_movement'] ?? 0),
                        ],
                        'style' => [
                            'font-weight' => 'bold',
                            'color' => 'rgb(6, 6, 174)',
                            'padding-left' => '15px',
                        ],
                    ]);
                }
            }

            // === Level 3: Category Summary ===
            if (!empty($categoryDTO->summary)) {
                $rows->push([
                    'data' => [
                        '',
                        $categoryDTO->summary['account_name'] ?? 'Total ' . $categoryName,
                        $toNumber($categoryDTO->summary['net_movement'] ?? 0),
                    ],
                    'style' => [
                        'font-weight' => 'bold',
                        'color' => 'rgb(0, 0, 128)',
                    ],
                ]);
            }

            // Spacer row between categories
            $rows->push(['data' => ['', '', '']]);
        }

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
                            'default' => 'Cashflow Direct Method',
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
