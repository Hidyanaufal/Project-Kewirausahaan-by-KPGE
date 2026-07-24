<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\EnhancedReport;
use App\Contracts\ExportableReport;
use App\DTO\ReportDTO;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\InvoiceStatus;
use App\Filament\Forms\Components\DateRangeSelect;
use App\Helpers\AuthHelper;
use App\Models\Accounting\Account;
use App\Models\Accounting\Invoice;
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
use Illuminate\Support\Facades\DB;
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

        // Trial Balance uses as-of date; Advance template shows activity from earliest txn through as-of.
        $end = Carbon::parse($this->getFilterState('asOfDate'))->endOfDay();
        $start = Carbon::parse($this->reportService->getEarliestTransactionDate((int) $company->id) ?? $end->toDateString())->startOfDay();
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $startFormatted = $start->format('d M Y');
        $endFormatted = $end->format('d M Y');

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

        // Ambil semua akun aktif (exclude parent/header + clearing OBE/CYE)
        $excludedIds = Account::trialBalanceExcludedAccountIdsForCompany((int) $company->id)->all();
        $accounts = Account::where('archived', false)
            ->where('company_id', $company->id)
            ->when($excludedIds !== [], fn ($q) => $q->whereNotIn('id', $excludedIds))
            ->get(['id', 'code', 'name', 'type']);

        // Urutkan berdasarkan AccountType yang diinginkan
        $typeOrder = array_flip(array_map(fn($type) => $type->value, $this->getOrderedAccountTypes()));
        $accounts = $accounts->sortBy(function ($account) use ($typeOrder) {
            $typeValue = $account->type?->value ?? '';
            return $typeOrder[$typeValue] ?? 999; // Type yang tidak ada di urutan akan di akhir
        })->values();

        $accountIds = $accounts->pluck('id')->toArray();
        $scale = 100; // ubah ke 1 jika amount sudah dalam rupiah
        $cancelled = InvoiceStatus::Cancelled->value;

        // Opening balance: semua transaksi sebelum tanggal start
        $opening = DB::table('journal_entries')
            ->join('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
            ->leftJoin('invoices', function ($join) {
                $join->on('invoices.id', '=', 'transactions.transactionable_id')
                    ->where('transactions.transactionable_type', '=', Invoice::class);
            })
            ->select(
                'journal_entries.account_id',
                DB::raw("SUM(CASE WHEN journal_entries.type = 'debit' THEN journal_entries.amount ELSE 0 END) as opening_debit"),
                DB::raw("SUM(CASE WHEN journal_entries.type = 'credit' THEN journal_entries.amount ELSE 0 END) as opening_credit")
            )
            ->where('journal_entries.company_id', $company->id)
            ->whereIn('journal_entries.account_id', $accountIds)
            ->where('transactions.posted_at', '<', $start->toDateString())
            ->where(function ($q) use ($cancelled) {
                $q->where('transactions.transactionable_type', '!=', Invoice::class)
                    ->orWhereNull('invoices.id')
                    ->orWhere('invoices.status', '!=', $cancelled);
            })
            ->groupBy('journal_entries.account_id')
            ->pluck('opening_debit', 'journal_entries.account_id')
            ->toArray();

        $openingCredit = DB::table('journal_entries')
            ->join('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
            ->leftJoin('invoices', function ($join) {
                $join->on('invoices.id', '=', 'transactions.transactionable_id')
                    ->where('transactions.transactionable_type', '=', Invoice::class);
            })
            ->select(
                'journal_entries.account_id',
                DB::raw("SUM(CASE WHEN journal_entries.type = 'credit' THEN journal_entries.amount ELSE 0 END) as opening_credit")
            )
            ->where('journal_entries.company_id', $company->id)
            ->whereIn('journal_entries.account_id', $accountIds)
            ->where('transactions.posted_at', '<', $start->toDateString())
            ->where(function ($q) use ($cancelled) {
                $q->where('transactions.transactionable_type', '!=', Invoice::class)
                    ->orWhereNull('invoices.id')
                    ->orWhere('invoices.status', '!=', $cancelled);
            })
            ->groupBy('journal_entries.account_id')
            ->pluck('opening_credit', 'journal_entries.account_id')
            ->toArray();

        // Mutasi selama periode (Change)
        $period = DB::table('journal_entries')
            ->join('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
            ->leftJoin('invoices', function ($join) {
                $join->on('invoices.id', '=', 'transactions.transactionable_id')
                    ->where('transactions.transactionable_type', '=', Invoice::class);
            })
            ->select(
                'journal_entries.account_id',
                DB::raw("SUM(CASE WHEN journal_entries.type = 'debit' THEN journal_entries.amount ELSE 0 END) as period_debit"),
                DB::raw("SUM(CASE WHEN journal_entries.type = 'credit' THEN journal_entries.amount ELSE 0 END) as period_credit")
            )
            ->where('journal_entries.company_id', $company->id)
            ->whereIn('journal_entries.account_id', $accountIds)
            ->whereBetween('transactions.posted_at', [$start->toDateString(), $end->toDateString()])
            ->where(function ($q) use ($cancelled) {
                $q->where('transactions.transactionable_type', '!=', Invoice::class)
                    ->orWhereNull('invoices.id')
                    ->orWhere('invoices.status', '!=', $cancelled);
            })
            ->groupBy('journal_entries.account_id')
            ->get()
            ->keyBy('account_id');

        $rows = collect();

        // Total keseluruhan
        $totals = [
            'op_debit' => 0,
            'op_credit' => 0,
            'chg_debit' => 0,
            'chg_credit' => 0,
            'end_debit' => 0,
            'end_credit' => 0,
        ];

        foreach ($accounts as $account) {
            $aid = $account->id;

            $opDebit = ($opening[$aid] ?? 0);
            $opCredit = ($openingCredit[$aid] ?? 0);

            $chgDebit = $period[$aid]->period_debit ?? 0;
            $chgCredit = $period[$aid]->period_credit ?? 0;

            // Hitung ending balance
            $endDebit = 0;
            $endCredit = 0;
            $balance = ($opDebit - $opCredit) + ($chgDebit - $chgCredit);

            if ($balance > 0) {
                $endDebit = $balance;
            } elseif ($balance < 0) {
                $endCredit = abs($balance);
            }

            // Skip akun tanpa aktivitas
            if ($opDebit == 0 && $opCredit == 0 && $chgDebit == 0 && $chgCredit == 0 && $endDebit == 0 && $endCredit == 0) {
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

            $totals['op_debit'] += $opDebit / $scale;
            $totals['op_credit'] += $opCredit / $scale;
            $totals['chg_debit'] += $chgDebit / $scale;
            $totals['chg_credit'] += $chgCredit / $scale;
            $totals['end_debit'] += $endDebit / $scale;
            $totals['end_credit'] += $endCredit / $scale;
        }

        // Tambahkan total di akhir
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
            'filename'      => "{$company->name} Trial Balance Advance {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => "Trial Balance (Advance)",
            'report_period' => "From {$startFormatted} to {$endFormatted}",
            'columns'       => $header,
            'rows'          => $rows->toArray(),
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

        $toNumber = fn($v) => is_null($v)
            ? 0
            : (float) str_replace(',', '.', preg_replace('/[^\d.]/', '', $v));

        $data = [
            'filename'      => "{$company->name} Trial Balance Classic {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => "Trial Balance Classic",
            'report_period' => "As of {$asOfFormatted}",
            'columns'       => [
                "Account No",
                "Account Name",
                "Debit",
                "Credit",
            ],
            'rows'          => [],
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
        $typeOrder = array_flip(array_map(fn($type) => $type->value, $this->getOrderedAccountTypes()));
        
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
        if (!empty($accountIds)) {
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

                $debit  = $toNumber($item['debit_balance'] ?? 0) / 100;
                $credit = $toNumber($item['credit_balance'] ?? 0) / 100;

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
                    $toNumber($totals['debit_balance']) / 100,
                    $toNumber($totals['credit_balance']) / 100,
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

    public function exportXLSX($template = null): BinaryFileResponse
    {
        return $this->exportService->exportToExcelEnhanced(
            data: $this->buildReportEnhanced($template)
        );
    }
}
