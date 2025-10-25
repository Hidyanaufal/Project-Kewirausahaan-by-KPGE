<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\EnhancedReport;
use App\Contracts\ExportableReport;
use App\DTO\ReportDTO;
use App\Filament\Company\Pages\Concerns\HasReportTabs;
use App\Filament\Company\Resources\Addons\ProjectResource;
use App\Helpers\AuthHelper;
use App\Models\Company;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\Column;
use App\Transformers\IncomeStatementReportTransformer;
use Carbon\Carbon;
use Filament\Forms\Form;
use Filament\Support\Enums\Alignment;
use Guava\FilamentClusters\Forms\Cluster;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncomeStatement extends BaseReportPage implements EnhancedReport
{
    use HasReportTabs;

    protected static string $view = 'filament.company.pages.reports.income-statement';

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
                ->label('ACCOUNTS')
                ->alignment(Alignment::Left),
            Column::make('net_movement')
                ->label($this->getDisplayDateRange())
                ->alignment(Alignment::Right),
        ];
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->columns(4)
            ->schema([
                $this->getDateRangeFormComponent(),
                Cluster::make([
                    $this->getStartDateFormComponent(),
                    $this->getEndDateFormComponent(),
                ])->extraFieldWrapperAttributes([
                    'class' => 'report-hidden-label',
                ]),
                ProjectResource::options()
                    ->required(false),
            ]);
    }

    protected function buildReport(array $columns): ReportDTO
    {
        return $this->reportService->buildIncomeStatementReport(
            $this->getFormattedStartDate(),
            $this->getFormattedEndDate(),
            $columns,
            $this->getFilterState('project_id'),
            $this->getFilterState('company_id'),
        );
    }

    protected function getTransformer(ReportDTO $reportDTO): ExportableReport
    {
        return new IncomeStatementReportTransformer($reportDTO);
    }

    public function buildReportEnhanced(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $report = $this->report;

        $start = Carbon::parse($report->getStartDate());
        $end = Carbon::parse($report->getEndDate());
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $startFormatted = $start->format('d M Y');
        $endFormatted = $end->format('d M Y');
        $startShort = $start->format('j M');
        $endShort = $end->format('j M Y');

        $toNumber = fn($v) => is_null($v)
            ? 0
            : (float) str_replace(',', '.', preg_replace('/[^\d]/', '', $v));

        $data = [
            'filename'           => "{$company->name} {$report->getTitle()} {$now}.pdf",
            'company_name'       => $company->name,
            'report_title'       => $report->getTitle(),
            'report_period'      => "From {$startFormatted} to {$endFormatted}",
            'columns'            => [
                "Description",
                "{$startShort} - {$endShort}"
            ],
            'rows'               => [],
        ];

        $rows = collect();

        foreach ($report->getCategories() as $cat) {
            $headerName = strtoupper($cat->header['account_name'] ?? 'UNKNOWN');
            $rows->push([
                'data' => [$headerName],
                'style' => ['font-weight' => 'bold'],
            ]);

            foreach ($cat->data as $item) {
                $name = is_array($item['account_name'])
                    ? ($item['account_name']['name'] ?? '')
                    : $item['account_name'];

                $rows->push([
                    'data' => [
                        $name,
                        $toNumber($item['net_movement'] ?? 0),
                    ],
                    'style' => ['padding-left' => '20px'],
                ]);
            }

            $rows->push([
                'data' => [
                    "Total Cost of {$headerName}",
                    $toNumber($cat->summary['net_movement'] ?? 0),
                ],
                'style' => ['font-weight' => 'bold'],
            ]);
        }

        $rows->push([
            'data' => ['GROSS PROFIT', $toNumber($report->getGrossProfit()['net_movement'])],
            'style' => ['font-weight' => 'bold'],
        ]);

        $rows->push([
            'data' => ['NET PROFIT/LOSS (Before Tax)', $toNumber($report->getOverallTotals()['net_movement'])],
            'style' => ['font-weight' => 'bold'],
        ]);

        $rows->push([
            'data' => ['NET PROFIT/LOSS (After Tax)', $toNumber($report->getOverallTotals()['net_movement'])],
            'style' => ['font-weight' => 'bold'],
        ]);

        $data['rows'] = $rows->toArray();

        return $data;
    }

    public function exportCSV(): StreamedResponse
    {
        return $this->exportService->exportToCsv(
            $this->company,
            $this->report,
            $this->getFilterState('startDate'),
            $this->getFilterState('endDate'),
            $this->getActiveTab()
        );
    }

    public function exportXLSX(): BinaryFileResponse
    {
        return $this->exportService->exportToExcel(
            $this->company,
            $this->report,
            $this->getFilterState('startDate'),
            $this->getFilterState('endDate'),
            $this->getActiveTab()
        );
    }

    public function exportPDF(): StreamedResponse
    {
        return $this->exportService->exportToPdfEnhanced(
            data: $this->buildReportEnhanced()
        );
    }
}
