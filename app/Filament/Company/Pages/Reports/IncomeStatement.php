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
        $startDate = $report->getStartDate();
        $endDate = $report->getEndDate();

        $formattedStartDate = Carbon::parse($startDate)->toDateString();
        $formattedEndDate = Carbon::parse($endDate)->toDateString();
        $dateLabel = $formattedStartDate . ' to ' . $formattedEndDate;
        $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
        $filename = $company->name . ' ' . $report->getTitle() . ' ' . $dateLabel . ' ' . $timestamp . '.pdf';

        $reportPeriod = "From {$startDate} to {$endDate}";
        $reportPeriodDate = "{$startDate} - {$endDate}";
        $data = [
            'filename'              => $filename,
            'company_name'          => $company->name,
            'report_title'          => $report->getTitle(),
            'report_period'         => $reportPeriod,
            'report_period_date'    => $reportPeriodDate,
            'categories'            => [],
        ];

        $categories = $report->getCategories();

        function toNumber($value)
        {
            if (is_null($value)) return 0;
            if (is_numeric($value)) return $value;

            $numeric = str_replace(['Rp', '.', ' '], '', $value);
            $numeric = str_replace(',', '.', $numeric);

            return (float) $numeric;
        }

        foreach ($categories as $categoryDTO) {
            $categoryName = strtoupper($categoryDTO->header['account_name'] ?? 'UNKNOWN');
            $items = collect($categoryDTO->data)->map(function ($item) {
                return [
                    'name' => is_array($item['account_name'])
                        ? ($item['account_name']['name'] ?? '')
                        : $item['account_name'],
                    'value' => toNumber($item['net_movement'] ?? 0),
                ];
            })->toArray();

            $categories[] = [
                'name' => $categoryName,
                'items' => $items,
            ];

            $categories[] = [
                'name' => 'Total Cost of ' . $categoryName,
                'value' => toNumber($categoryDTO->summary['net_movement'] ?? 0),
            ];
        }

        $categories = array_filter(
            $categories,
            fn($item) => !($item instanceof \App\DTO\ReportCategoryDTO)
        );

        $data['categories'] = $categories;
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
