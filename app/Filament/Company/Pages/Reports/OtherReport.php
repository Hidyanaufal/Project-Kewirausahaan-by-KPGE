<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\EnhancedReport;
use App\Contracts\ExportableReport;
use App\DTO\ReportDTO;
use App\Filament\Company\Pages\Concerns\HasReportTabs;
use App\Filament\Company\Resources\Addons\ProjectResource;
use App\Helpers\AuthHelper;
use App\Models\Accounting\Account;
use App\Models\Company;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\Column;
use App\Transformers\IncomeStatementReportTransformer;
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

class OtherReport extends BaseReportPage implements EnhancedReport
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

    public function buildReportEnhanced(
        ?string $template = null,
        string $format = 'pdf'
    ): array {
        $template = $template ?? 'default';
        return match ($template) {
            'default' => $this->__buildReportDefault(),
            'all' => $this->__buildReportAllJournal(),
            'bank' => $this->__buildReportBankBook(),
            'journal' => $this->__buildReportGeneralJournalVoucher(),
            'ledger_detail' => $this->__buildReportLedgerDetail(),
            'ledger_summary' => $this->__buildReportLedgerSummary(),
            'equity' => $this->__buildReportStatementOfOwnerEquity(),
            default => [
                'title' => 'Unknown Report Template',
                'headers' => [],
                'rows' => [],
            ],
        };
    }

    public function __buildReportDefault(): array
    {
        // contoh implementasi laporan perubahan modal
        return [];
    }

    public function __buildReportAllJournal(): array
    {
        // contoh implementasi laporan jurnal lengkap
        return [];
    }

    public function __buildReportBankBook(): array
    {
        // contoh implementasi laporan buku bank
        return [];
    }

    public function __buildReportGeneralJournalVoucher(): array
    {
        // implementasi laporan jurnal voucher
        return [];
    }

    public function __buildReportLedgerDetail(): array
    {
        // implementasi laporan buku besar detail
        return [];
    }

    public function __buildReportLedgerSummary(): array
    {
        // implementasi laporan buku besar ringkasan
        return [];
    }

    public function __buildReportStatementOfOwnerEquity(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());

        $report = $this->report;

        $toNumber = fn($v) => is_null($v)
            ? 0
            : (float) str_replace(',', '.', preg_replace('/[^\d]/', '', $v));

        $start = Carbon::parse($report->getStartDate());
        $end = Carbon::parse($report->getEndDate());
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $startFormatted = $start->format('d M Y');
        $endFormatted = $end->format('d M Y');

        $rows = collect();

        $header = [
            'Description.',
            'Balance',
        ];

        /**
         * Owner's equity at begining period = COA.OPENING BALANCE EQUITY
         * Net Income = INCOME_STATEMENT.NET_EARNING
         * Investment on period  = 0
         * Less Withdrawals = 0
         * Increase in Owner's Equity = 0
         * Owner's equity at end of period = 0
         */

        $openingBalance = Account::getOpeningBalanceAccount();

        $rows->push([
            'data' => ["Owner's equity at begining period", $openingBalance->ending_balance->getValue()],
            'style' => ['font-weight' => 'bold'],
        ]);

        $rows->push([
            'data' => ['Net Income', $toNumber($report->getOverallTotals()['net_movement']) / 100],
            'style' => ['padding-left' => '15px'],
        ]);

        $rows->push([
            'data' => ['Investment on period', 0],
            'style' => ['padding-left' => '15px'],
        ]);

        $rows->push([
            'data' => ['Less Withdrawals', 0],
            'style' => ['padding-left' => '15px'],
        ]);

        $rows->push([
            'data' => ["Increase in Owner's Equity", 0],
            'style' => ['font-weight' => 'bold'],
        ]);

        $rows->push([
            'data' => ["Owner's equity at end of period", 0],
            'style' => ['font-weight' => 'bold'],
        ]);

        return [
            'filename'      => "{$company->name} Statement of Owners Equity Changes {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => 'Statement of Owners Equity Changes',
            'report_period' => "From {$startFormatted} to {$endFormatted}",
            'columns'       => $header,
            'rows'          => $rows->toArray(),
        ];
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
                            'default'        => 'Perubahan Modal',
                            'all'            => 'Financial Ratio',
                            'bank'           => 'Bank Book',
                            'journal'        => 'General Journal Voucher',
                            'ledger_detail'  => 'General Ledger Detail',
                            'ledger_summary' => 'General Ledger Summary',
                            'equity'         => 'Statement of Owner Equity Changes',
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
        return $this->exportService->exportToCsv(
            $this->company,
            $this->report,
            $this->getFilterState('startDate'),
            $this->getFilterState('endDate'),
            $this->getActiveTab()
        );
    }

    public function exportXLSX(?string $template = null): BinaryFileResponse
    {
        return $this->exportService->exportToExcelEnhanced(
            data: $this->buildReportEnhanced(
                template: $template,
                format: 'xlsx'
            )
        );
    }

    public function exportPDF(?string $template = null): StreamedResponse
    {
        return $this->exportService->exportToPdfEnhanced(
            data: $this->buildReportEnhanced(
                template: $template,
                format: 'pdf'
            )
        );
    }
}
