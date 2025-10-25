<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\EnhancedReport;
use App\Contracts\ExportableReport;
use App\DTO\ReportDTO;
use App\Filament\Company\Pages\Concerns\HasReportTabs;
use App\Filament\Forms\Components\DateRangeSelect;
use App\Helpers\AuthHelper;
use App\Models\Company;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\Column;
use App\Transformers\BalanceSheetReportTransformer;
use Carbon\Carbon;
use Filament\Forms\Form;
use Filament\Support\Enums\Alignment;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BalanceSheet extends BaseReportPage implements EnhancedReport
{
    use HasReportTabs;

    protected static string $view = 'filament.company.pages.reports.balance-sheet';

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
                ->toggleable(isToggledHiddenByDefault: true)
                ->alignment(Alignment::Left),
            Column::make('account_name')
                ->label('ACCOUNTS')
                ->alignment(Alignment::Left),
            Column::make('ending_balance')
                ->label($this->getDisplayAsOfDate())
                ->alignment(Alignment::Right),
        ];
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->inlineLabel()
            ->columns(3)
            ->schema([
                DateRangeSelect::make('dateRange')
                    ->label('As of')
                    ->selectablePlaceholder(false)
                    ->endDateField('asOfDate'),
                $this->getAsOfDateFormComponent()
                    ->hiddenLabel()
                    ->extraFieldWrapperAttributes([]),
            ]);
    }

    protected function buildReport(array $columns): ReportDTO
    {
        return $this->reportService->buildBalanceSheetReport($this->getFormattedAsOfDate(), $columns);
    }

    protected function getTransformer(ReportDTO $reportDTO): ExportableReport
    {
        return new BalanceSheetReportTransformer($reportDTO);
    }

    public function buildReportEnhanced(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $report = $this->report;

        $start = Carbon::parse($report->getStartDate());
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $startFormatted = $start->format('d M Y');
        $toNumber = fn($v) => is_null($v)
            ? 0
            : (float) str_replace(',', '.', preg_replace('/[^\d,]/', '', $v));

        $data = [
            'filename'           => "{$company->name} {$report->getTitle()} {$now}.pdf",
            'company_name'       => $company->name,
            'report_title'       => $report->getTitle(),
            'report_period'      => "As of {$startFormatted}",
            'columns'            => [
                "Description",
                "Balance"
            ],
            'rows'               => [],
        ];

        $rows = collect();

        // dd(
        //     __CLASS__,
        //     [
        //         'getCategories' => $report->getCategories()
        //     ]
        // );

        foreach ($report->getCategories() as $cat) {
            $headerName = strtoupper($cat->header['account_name'] ?? 'UNKNOWN');
            $rows->push([
                'data' => [$headerName],
                'style' => ['font-weight' => 'bold', 'font-size' => '11px'],
            ]);

            foreach ($cat->data as $item) {
                $name = is_array($item['account_name'])
                    ? ($item['account_name']['name'] ?? '')
                    : $item['account_name'];

                $rows->push([
                    'data' => [
                        $name,
                        $toNumber($item['ending_balance'] ?? 0),
                    ],
                    'style' => ['font-size' => '10px', 'padding-left' => '20px'],
                ]);
            }

            $rows->push([
                'data' => [
                    "Total Cost of {$headerName}",
                    $toNumber($cat->summary['ending_balance'] ?? 0),
                ],
                'style' => ['font-weight' => 'bold', 'font-size' => '10px'],
            ]);
        }

        $data['rows'] = $rows->toArray();

        return $data;
    }

    public function exportCSV(): StreamedResponse
    {
        return $this->exportService->exportToCsv($this->company, $this->report, endDate: $this->getFilterState('asOfDate'), activeTab: $this->getActiveTab());
    }

    public function exportPDF(): StreamedResponse
    {
        return $this->exportService->exportToPdfEnhanced(
            data: $this->buildReportEnhanced()
        );
    }

    public function exportXLSX(): BinaryFileResponse
    {
        return $this->exportService->exportToExcel($this->company, $this->report, $this->getFilterState('startDate'), $this->getFilterState('endDate'), $this->getActiveTab());
    }
}
