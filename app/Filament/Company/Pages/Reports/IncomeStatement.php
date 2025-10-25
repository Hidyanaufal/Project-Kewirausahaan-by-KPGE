<?php

namespace App\Filament\Company\Pages\Reports;

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

class IncomeStatement extends BaseReportPage
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

    protected function buildReportEnhanced(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $report = $this->report;

        $start = Carbon::parse($report->getStartDate());
        $end = Carbon::parse($report->getEndDate());
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $startFormatted = $start->format('d M Y'); // 01 Jan 2024
        $endFormatted = $end->format('d M Y');     // 31 Dec 2024
        $startShort = $start->format('j M');       // 1 Jan
        $endShort = $end->format('j M Y');         // 31 Dec 2024

        $data = [
            'filename'           => "{$company->name} {$report->getTitle()} {$startFormatted} to {$endFormatted} {$now}.pdf",
            'company_name'       => $company->name,
            'report_title'       => $report->getTitle(),
            'report_period'      => "From {$startFormatted} to {$endFormatted}",
            'report_period_date' => "{$startShort}-{$endShort}",
            'categories'         => [],
        ];

        $toNumber = fn($v) => is_null($v)
            ? 0
            : (float) str_replace(',', '.', preg_replace('/[^\d,]/', '', $v));


        $data['categories'] = collect($report->getCategories())->flatMap(function ($cat) use ($toNumber) {
            $name = strtoupper($cat->header['account_name'] ?? 'UNKNOWN');

            $items = collect($cat->data)->map(fn($i) => [
                'name'  => is_array($i['account_name'])
                    ? ($i['account_name']['name'] ?? '')
                    : $i['account_name'],
                'value' => $toNumber($i['net_movement'] ?? 0),
            ]);

            return [
                ['name' => $name, 'items' => $items],
                ['name' => "Total Cost of {$name}", 'value' => $toNumber($cat->summary['net_movement'] ?? 0)],
            ];
        })->toArray();

        $data['categories'][] = [
            'name'  => 'GROSS PROFIT',
            'value' => $toNumber($report->getGrossProfit()['net_movement'])
        ];

        $data['categories'][] = [
            'name'  => 'NET PROFIT/LOSS (Before Tax)',
            'value' => $toNumber($report->getOverallTotals()['net_movement'])
        ];

        $data['categories'][] = [
            'name'  => 'NET PROFIT/LOSS (After Tax)',
            'value' => $toNumber($report->getOverallTotals()['net_movement'])
        ];

        return $data;
    }

    protected function getTransformer(ReportDTO $reportDTO): ExportableReport
    {
        return new IncomeStatementReportTransformer($reportDTO);
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
