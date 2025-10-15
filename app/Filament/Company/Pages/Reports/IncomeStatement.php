<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\ExportableReport;
use App\DTO\ReportDTO;
use App\Filament\Company\Pages\Concerns\HasReportTabs;
use App\Filament\Forms\Addons\FilterProjectCompany;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\Column;
use App\Transformers\IncomeStatementReportTransformer;
use Filament\Forms\Form;
use Filament\Support\Enums\Alignment;
use Guava\FilamentClusters\Forms\Cluster;
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
                ...FilterProjectCompany::make(),
            ]);
    }

    protected function buildReport(array $columns): ReportDTO
    {
        return $this->reportService->buildIncomeStatementReport(
            $this->getFormattedStartDate(),
            $this->getFormattedEndDate(),
            $columns,
            $this->getFilterState('project_id')
        );
    }

    protected function getTransformer(ReportDTO $reportDTO): ExportableReport
    {
        return new IncomeStatementReportTransformer($reportDTO);
    }

    public function exportCSV(): StreamedResponse
    {
        return $this->exportService->exportToCsv($this->company, $this->report, $this->getFilterState('startDate'), $this->getFilterState('endDate'), $this->getActiveTab());
    }

    public function exportPDF(): StreamedResponse
    {
        return $this->exportService->exportToPdf($this->company, $this->report, $this->getFilterState('startDate'), $this->getFilterState('endDate'), $this->getActiveTab());
    }
}
