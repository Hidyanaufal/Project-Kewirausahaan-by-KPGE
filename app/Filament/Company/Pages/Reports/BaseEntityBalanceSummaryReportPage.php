<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\ExportableReport;
use App\DTO\ReportDTO;
use App\Enums\Accounting\DocumentEntityType;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\Column;
use App\Transformers\EntityBalanceSummaryReportTransformer;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Guava\FilamentClusters\Forms\Cluster;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class BaseEntityBalanceSummaryReportPage extends BaseReportPage
{
    protected static string $view = 'filament.company.pages.reports.detailed-report';

    protected ReportService $reportService;

    protected ExportService $exportService;

    abstract protected function getEntityType(): DocumentEntityType;

    public function boot(ReportService $reportService, ExportService $exportService): void
    {
        $this->reportService = $reportService;
        $this->exportService = $exportService;
    }

    public function getTable(): array
    {
        return [
            Column::make('entity_name')
                ->label($this->getEntityType()->getLabel())
                ->alignment(Alignment::Left),
            Column::make('total_balance')
                ->label('Total')
                ->toggleable()
                ->alignment(Alignment::Right),
            Column::make('paid_balance')
                ->label('Paid')
                ->toggleable()
                ->alignment(Alignment::Right),
            Column::make('unpaid_balance')
                ->label('Unpaid')
                ->toggleable()
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
            ]);
    }

    protected function buildReport(array $columns): ReportDTO
    {
        return $this->reportService->buildEntityBalanceSummaryReport(
            startDate: $this->getFormattedStartDate(),
            endDate: $this->getFormattedEndDate(),
            entityType: $this->getEntityType(),
            columns: $columns,
            companyId: $this->company->id
        );
    }

    protected function getTransformer(ReportDTO $reportDTO): ExportableReport
    {
        return new EntityBalanceSummaryReportTransformer($reportDTO, $this->getEntityType());
    }

    public function exportCSV(): StreamedResponse
    {
        return $this->exportService->exportToCsv(
            $this->company,
            $this->report,
            $this->getFilterState('startDate'),
            $this->getFilterState('endDate')
        );
    }

    public function exportPDF(): StreamedResponse
    {
        return $this->exportService->exportToPdf(
            $this->company,
            $this->report,
            $this->getFilterState('startDate'),
            $this->getFilterState('endDate')
        );
    }

    public function exportXLSX(): BinaryFileResponse
    {
        try {
            return $this->exportService->exportToExcelEnhancedFromReport(
                $this->company,
                $this->report,
                $this->getFilterState('startDate'),
                $this->getFilterState('endDate'),
            );
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Export XLSX dibatalkan (fallback)')
                ->body($e->getMessage())
                ->send();

            return $this->exportService->exportToExcel(
                $this->company,
                $this->report,
                $this->getFilterState('startDate'),
                $this->getFilterState('endDate'),
            );
        }
    }
}
