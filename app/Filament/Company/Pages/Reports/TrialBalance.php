<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\EnhancedReport;
use App\Contracts\ExportableReport;
use App\DTO\ReportDTO;
use App\Enums\Accounting\AccountType;
use App\Filament\Forms\Components\DateRangeSelect;
use App\Helpers\AuthHelper;
use App\Models\Accounting\Account;
use App\Models\Company;
use App\Services\AccountService;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\Column;
use App\Transformers\TrialBalanceReportTransformer;
use App\Utilities\Currency\CurrencyConverter;
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

    protected AccountService $accountService;

    public function boot(ReportService $reportService, ExportService $exportService, AccountService $accountService): void
    {
        $this->reportService = $reportService;
        $this->exportService = $exportService;
        $this->accountService = $accountService;
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
                        return $this->exportXLSX($template);
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
                    ->default('standard')
                    ->disabled()
                    ->visible(false),
                    
                DateRangeSelect::make('dateRange')
                    ->label('As of')
                    ->selectablePlaceholder(false)
                    ->endDateField('asOfDate'),
                $this->getAsOfDateFormComponent(),
            ]);
    }

    protected function buildReport(array $columns): ReportDTO
    {
        return $this->reportService->buildTrialBalanceReport(
            $this->getFilterState('reportType'),
            $this->getFormattedAsOfDate(),
            $columns,
            projectId: null,
            companyId: $this->company->id
        );
    }

    protected function getTransformer(ReportDTO $reportDTO): ExportableReport
    {
        return new TrialBalanceReportTransformer($reportDTO);
    }

    /**
     * Get ordered AccountTypes for Trial Balance
     * Order: CurrentAsset → NonCurrentAsset → ContraAsset → CurrentLiability → NonCurrentLiability → ContraLiability → Equity → OperatingRevenue → OperatingExpense → NonOperatingRevenue → NonOperatingExpense
     */
    private function getOrderedAccountTypes(): array
    {
        return [
            AccountType::CurrentAsset,
            AccountType::NonCurrentAsset,
            AccountType::ContraAsset,
            AccountType::CurrentLiability,
            AccountType::NonCurrentLiability,
            AccountType::ContraLiability,
            AccountType::Equity,
            AccountType::OperatingRevenue,
            AccountType::OperatingExpense,
            AccountType::NonOperatingRevenue,
            AccountType::NonOperatingExpense,
        ];
    }

    public function buildReportEnhanced(
        ?string $template = null,
        string $format = 'pdf',
    ): array {
        if ($template == 'advance') {
            return $this->__buildReportAdvance();
        }
        return $this->__buildReportDefault();
    }

    public function __buildReportAdvance(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $companyId = (int) $company->id;

        // Same datetime bounds as ReportService::buildTrialBalanceReport / Filament UI
        // (date-only as-of → endOfDay so cutover OB at 23:59:59 is included).
        $end = Carbon::parse($this->getFilterState('asOfDate'))->endOfDay();
        $start = Carbon::parse(
            $this->reportService->getEarliestTransactionDate($companyId) ?? $end->toDateTimeString()
        );
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $header = [
            'Account No',
            'Account Name',
            'Op Bal Debit',
            'Op Bal Credit',
            'Change Debit',
            'Change Credit',
            'End Bal Debit',
            'End Bal Credit',
        ];

        $excludedIds = Account::trialBalanceExcludedAccountIdsForCompany($companyId)->flip();

        $accounts = $this->accountService->getAccountBalances(
            $start->toDateTimeString(),
            $end->toDateTimeString(),
            [],
            null,
            $companyId,
        )
            ->orderByRaw('LENGTH(code), code')
            ->get();

        $typeOrder = array_flip(array_map(fn ($type) => $type->value, $this->getOrderedAccountTypes()));
        $accounts = $accounts->sortBy(function ($account) use ($typeOrder) {
            $typeValue = $account->type?->value ?? '';

            return $typeOrder[$typeValue] ?? 999;
        })->values();

        $rows = collect();
        $totals = [
            'op_debit' => 0,
            'op_credit' => 0,
            'chg_debit' => 0,
            'chg_credit' => 0,
            'end_debit' => 0,
            'end_credit' => 0,
        ];

        foreach ($accounts as $account) {
            if ($excludedIds->has($account->id)) {
                continue;
            }

            $accountBalances = $this->reportService->calculateAccountBalances($account);
            $endingBalance = (int) ($accountBalances['ending_balance'] ?? $accountBalances['net_movement'] ?? 0);
            $startingBalance = (int) ($accountBalances['starting_balance'] ?? 0);
            $chgDebit = (int) ($account->total_debit ?? 0);
            $chgCredit = (int) ($account->total_credit ?? 0);

            $opSides = $this->reportService->calculateTrialBalancesForAccount($account, $startingBalance);
            $endSides = $this->reportService->calculateTrialBalancesForAccount($account, $endingBalance);

            $opDebit = (int) ($opSides['debit_balance'] ?? 0);
            $opCredit = (int) ($opSides['credit_balance'] ?? 0);
            $endDebit = (int) ($endSides['debit_balance'] ?? 0);
            $endCredit = (int) ($endSides['credit_balance'] ?? 0);

            if (
                $opDebit === 0 && $opCredit === 0
                && $chgDebit === 0 && $chgCredit === 0
                && $endDebit === 0 && $endCredit === 0
            ) {
                continue;
            }

            // Skip zero ending like the UI trial balance (still show activity-only rows only if ending ≠ 0).
            if ($endingBalance === 0) {
                continue;
            }

            $rows->push([
                'data' => [
                    '__str' . $account->code,
                    $account->name,
                    $opDebit / 100,
                    $opCredit / 100,
                    $chgDebit / 100,
                    $chgCredit / 100,
                    $endDebit / 100,
                    $endCredit / 100,
                ],
            ]);

            $totals['op_debit'] += $opDebit;
            $totals['op_credit'] += $opCredit;
            $totals['chg_debit'] += $chgDebit;
            $totals['chg_credit'] += $chgCredit;
            $totals['end_debit'] += $endDebit;
            $totals['end_credit'] += $endCredit;
        }

        $rows->push([
            'data' => [
                '',
                'TOTAL',
                $totals['op_debit'] / 100,
                $totals['op_credit'] / 100,
                $totals['chg_debit'] / 100,
                $totals['chg_credit'] / 100,
                $totals['end_debit'] / 100,
                $totals['end_credit'] / 100,
            ],
            'style' => [
                'font-weight' => 'bold',
            ],
        ]);

        return [
            'filename' => "{$company->name} Trial Balance Advance {$now}.pdf",
            'company_name' => $company->name,
            'report_title' => 'Trial Balance (Advance)',
            'report_period' => 'From ' . $start->format('d M Y') . ' to ' . $end->format('d M Y'),
            'columns' => $header,
            'rows' => $rows->toArray(),
        ];
    }

    public function __buildReportDefault(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $report = $this->report;

        // ReportDTO for Trial Balance has no startDate; use as-of filter (Carbon::parse(null) would become today).
        $asOf = Carbon::parse($this->getFilterState('asOfDate'));
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $asOfFormatted = $asOf->format('d M Y');

        // Transformer balances are already major-unit money strings (formatCentsToMoney) — do not /100 again.
        $toMajor = static function ($v): float {
            if ($v === null || $v === '') {
                return 0.0;
            }
            if (is_int($v) || is_float($v)) {
                return (float) $v;
            }

            return CurrencyConverter::convertToFloat((string) $v);
        };

        $data = [
            'filename' => "{$company->name} Trial Balance Classic {$now}.pdf",
            'company_name' => $company->name,
            'report_title' => 'Trial Balance Classic',
            'report_period' => "As of {$asOfFormatted}",
            'columns' => [
                'Account No',
                'Account Name',
                'Debit',
                'Credit',
            ],
            'rows' => [],
        ];

        $rows = collect();

        // Urutkan categories berdasarkan urutan AccountType yang diinginkan
        $categoryOrder = [
            'Assets' => 1,      // Asset (CurrentAsset, NonCurrentAsset, ContraAsset)
            'Liabilities' => 2, // Liability (CurrentLiability, NonCurrentLiability, ContraLiability)
            'Equity' => 3,
            'Revenue' => 4,     // Revenue (OperatingRevenue, NonOperatingRevenue)
            'Expenses' => 5,    // Expense (OperatingExpense, NonOperatingExpense)
        ];

        $categories = $report->getCategories();

        // Sort categories berdasarkan urutan
        usort($categories, function ($a, $b) use ($categoryOrder) {
            $aName = strtoupper($a->header['account_name'] ?? '');
            $bName = strtoupper($b->header['account_name'] ?? '');

            // Normalize category names
            $aKey = match (true) {
                str_contains($aName, 'ASSET') => 'Assets',
                str_contains($aName, 'LIABILIT') => 'Liabilities',
                str_contains($aName, 'EQUITY') => 'Equity',
                str_contains($aName, 'REVENUE') => 'Revenue',
                str_contains($aName, 'EXPENSE') => 'Expenses',
                default => $aName,
            };

            $bKey = match (true) {
                str_contains($bName, 'ASSET') => 'Assets',
                str_contains($bName, 'LIABILIT') => 'Liabilities',
                str_contains($bName, 'EQUITY') => 'Equity',
                str_contains($bName, 'REVENUE') => 'Revenue',
                str_contains($bName, 'EXPENSE') => 'Expenses',
                default => $bName,
            };

            $aOrder = $categoryOrder[$aKey] ?? 999;
            $bOrder = $categoryOrder[$bKey] ?? 999;

            if ($aOrder !== $bOrder) {
                return $aOrder <=> $bOrder;
            }

            return $aName <=> $bName;
        });

        // Urutkan accounts dalam setiap category berdasarkan type
        $typeOrder = array_flip(array_map(fn ($type) => $type->value, $this->getOrderedAccountTypes()));

        // Get account types untuk sorting
        $accountIds = [];
        foreach ($categories as $cat) {
            foreach ($cat->data as $item) {
                $accountId = is_array($item['account_name'])
                    ? ($item['account_name']['id'] ?? null)
                    : null;
                if ($accountId) {
                    $accountIds[] = $accountId;
                }
            }
        }

        $accountTypes = [];
        if (! empty($accountIds)) {
            $accounts = Account::whereIn('id', $accountIds)->get(['id', 'type']);
            foreach ($accounts as $acc) {
                $accountTypes[$acc->id] = $acc->type?->value ?? '';
            }
        }

        foreach ($categories as $cat) {
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

            // Sort accounts dalam category berdasarkan type
            $sortedData = collect($cat->data)->sortBy(function ($item) use ($accountTypes, $typeOrder) {
                $accountId = is_array($item['account_name'])
                    ? ($item['account_name']['id'] ?? null)
                    : null;
                $typeValue = $accountId ? ($accountTypes[$accountId] ?? '') : '';

                return $typeOrder[$typeValue] ?? 999;
            })->values();

            foreach ($sortedData as $item) {
                $accountCode = $item['account_code'] ?? '';
                $accountName = is_array($item['account_name'])
                    ? ($item['account_name']['name'] ?? '')
                    : $item['account_name'];

                $debit = $toMajor($item['debit_balance'] ?? 0);
                $credit = $toMajor($item['credit_balance'] ?? 0);

                $rows->push([
                    'data' => [
                        '__str' . $accountCode,
                        $accountName,
                        $debit,
                        $credit,
                    ],
                    'style' => ['padding-left' => '15px'],
                ]);
            }
        }

        $totals = $report->getOverallTotals();
        if (! empty($totals)) {
            $rows->push([
                'data' => [
                    '',
                    '',
                    $toMajor($totals['debit_balance'] ?? 0),
                    $toMajor($totals['credit_balance'] ?? 0),
                ],
                'style' => [
                    'font-weight' => 'bold',
                    'color' => 'rgb(6, 6, 174)',
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

    public function exportXLSX($template = null): BinaryFileResponse
    {
        return $this->exportService->exportToExcelEnhanced(
            data: $this->buildReportEnhanced($template)
        );
    }
}
