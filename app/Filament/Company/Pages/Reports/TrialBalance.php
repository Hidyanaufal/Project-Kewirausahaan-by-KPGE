<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\EnhancedReport;
use App\Contracts\ExportableReport;
use App\DTO\ReportDTO;
use App\Filament\Forms\Components\DateRangeSelect;
use App\Helpers\AuthHelper;
use App\Models\Company;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\Column;
use App\Transformers\TrialBalanceReportTransformer;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\MaxWidth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrialBalance extends BaseReportPage implements EnhancedReport
{
    protected static string $view = 'filament.company.pages.reports.trial-balance';

    protected ReportService $reportService;

    protected ExportService $exportService;

    public function boot(ReportService $reportService, ExportService $exportService): void
    {
        $this->reportService = $reportService;
        $this->exportService = $exportService;
    }

    protected function initializeDefaultFilters(): void
    {
        if (empty($this->getFilterState('reportType'))) {
            $this->setFilterState('reportType', 'standard');
        }
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
                            'default' => 'Trial Balance (Classic)',
                            'advance' => 'Trial Balance',
                        ])
                        ->required(),
                ])
                ->action(function (array $data) {
                    $format     = $data['format'];
                    $template   = $data['template'];

                    if ($format == 'xlsx') {
                        return $this->exportXLSX();
                    }

                    if ($format == 'pdf') {
                        return $this->exportPDF($template);
                    }
                })
        ];
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
            Column::make('debit_balance')
                ->label('DEBIT')
                ->alignment(Alignment::Right),
            Column::make('credit_balance')
                ->label('CREDIT')
                ->alignment(Alignment::Right),
        ];
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->columns(4)
            ->schema([
                Select::make('reportType')
                    ->label('Report type')
                    ->options([
                        'standard' => 'Standard',
                        'postClosing' => 'Post-Closing',
                    ])
                    ->selectablePlaceholder(false),
                DateRangeSelect::make('dateRange')
                    ->label('As of')
                    ->selectablePlaceholder(false)
                    ->endDateField('asOfDate'),
                $this->getAsOfDateFormComponent(),
            ]);
    }

    protected function buildReport(array $columns): ReportDTO
    {
        return $this->reportService->buildTrialBalanceReport($this->getFilterState('reportType'), $this->getFormattedAsOfDate(), $columns);
    }

    protected function getTransformer(ReportDTO $reportDTO): ExportableReport
    {
        return new TrialBalanceReportTransformer($reportDTO);
    }

    public function buildReportEnhanced($template = null): array
    {
        if ($template == 'advance') {
            return $this->__buildReportAdvance();
        }
        return $this->__buildReportDefault();
    }

    public function __buildReportAdvance(): array
    {
        return [];
    }

    public function __buildReportDefault(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $report = $this->report;

        $start = Carbon::parse($report->getStartDate());
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $startFormatted = $start->format('d M Y');

        $toNumber = fn($v) => is_null($v)
            ? 0
            : (float) str_replace(',', '.', preg_replace('/[^\d]/', '', $v));

        $data = [
            'filename'      => "{$company->name} Trial Balance Classic {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => "Trial Balance Classic",
            'report_period' => "As of {$startFormatted}",
            'columns'       => [
                "Account No",
                "Account Name",
                "Debit",
                "Credit",
            ],
            'rows'          => [],
        ];

        $rows = collect();

        foreach ($report->getCategories() as $cat) {
            $headerName = strtoupper($cat->header['account_name'] ?? 'UNKNOWN');
            $rows->push([
                'data' => [
                    $headerName,
                    '',
                    '',
                    '',
                ],
                'style' => [
                    'font-weight' => 'bold',
                ],
            ]);

            foreach ($cat->data as $item) {
                $accountCode = $item['account_code'] ?? '';
                $accountName = is_array($item['account_name'])
                    ? ($item['account_name']['name'] ?? '')
                    : $item['account_name'];

                $debit  = $toNumber($item['debit_balance'] ?? 0);
                $credit = $toNumber($item['credit_balance'] ?? 0);

                $rows->push([
                    'data' => [
                        '__str' . $accountCode,
                        $accountName,
                        $debit,
                        $credit
                    ],
                    'style' => ['padding-left' => '15px'],
                ]);
            }
        }

        $totals = $report->getOverallTotals();
        if (!empty($totals)) {
            $rows->push([
                'data' => [
                    '',
                    '',
                    $toNumber($totals['debit_balance']),
                    $toNumber($totals['credit_balance']),
                ],
                'style' => [
                    'font-weight' => 'bold',
                    'color'       => 'rgb(6, 6, 174)',
                ],
            ]);
        }

        $data['rows'] = $rows->toArray();

        return $data;
    }

    public function exportCSV(): StreamedResponse
    {
        return $this->exportService->exportToCsv($this->company, $this->report, endDate: $this->getFilterState('asOfDate'));
    }

    public function exportPDF($template = null): StreamedResponse
    {
        return $this->exportService->exportToPdfEnhanced(
            data: $this->buildReportEnhanced($template)
        );
    }

    public function exportXLSX(): BinaryFileResponse
    {
        return $this->exportService->exportToExcel($this->company, $this->report, $this->getFilterState('startDate'), $this->getFilterState('endDate'));
    }
}
