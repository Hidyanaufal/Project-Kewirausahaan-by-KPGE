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
use Illuminate\Support\Facades\DB;
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
            'history' => $this->__buildReportLedgerHistory(),
            'journal' => $this->__buildReportGeneralJournalVoucher(),
            'ledger_detail' => $this->__buildReportLedgerDetail(),
            'ledger_summary' => $this->__buildReportLedgerSummary(),
            'equity' => $this->__buildReportStatementOfOwnerEquity(),
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

    public function __buildReportLedgerHistory(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $start = Carbon::parse($this->getFilterState('startDate'));
        $end = Carbon::parse($this->getFilterState('endDate'));
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $header = [
            'Date',
            'Source Type',
            'Source No.',
            'Account No.',
            'Account Name',
            'Description',
            'Debit Amount',
            'Credit Amount',
        ];

        $transactions = \App\Models\Accounting\Transaction::with([
            'journalEntries.account'
        ])
            ->whereBetween('posted_at', [$start, $end])
            ->where('company_id', $company->id)
            ->orderBy('posted_at')
            ->get();

        $rows = collect();

        foreach ($transactions as $trx) {
            foreach ($trx->journalEntries as $entry) {
                $debit = $entry->type->value === 'debit' ? $entry->amount : 0;
                $credit = $entry->type->value === 'credit' ? $entry->amount : 0;

                $rows->push([
                    'data' => [
                        $trx->posted_at?->format('d/m/Y'),
                        strtoupper($trx->type->value ?? '-'),
                        $trx->transaction_code,
                        '__str' . $entry->account->code,
                        $entry->account->name ?? '',
                        \Illuminate\Support\Str::limit($trx->description, 20),
                        $debit / 100,
                        $credit / 100
                    ],
                ]);
            }
        }

        return [
            'filename'      => "{$company->name} General Ledger History {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => 'General Ledger History',
            'report_period' => "From {$start->format('d M Y')} to {$end->format('d M Y')}",
            'columns'       => $header,
            'rows'          => $rows->toArray(),
        ];
    }

    public function __buildReportGeneralJournalVoucher(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $start = Carbon::parse($this->getFilterState('startDate'));
        $end = Carbon::parse($this->getFilterState('endDate'));
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $header = [
            'JV Date',
            'GL Account No.',
            'GL Account Name',
            'Description',
            'Debit',
            'Credit',
            'Dept. Name',
        ];

        $transactions = \App\Models\Accounting\Transaction::with([
            'journalEntries.account',
            'project'
        ])
            ->whereBetween('posted_at', [$start, $end])
            ->where('company_id', $company->id)
            ->where('type', \App\Enums\Accounting\TransactionType::Journal)
            ->orderBy('posted_at')
            ->get();

        $rows = collect();

        foreach ($transactions as $trx) {
            $rows->push([
                'data' => ["Journal No: {$trx->transaction_code}"],
                'style' => ['font-weight' => 'bold'],
            ]);

            foreach ($trx->journalEntries as $entry) {
                $debit = $entry->type->value === 'debit' ? $entry->amount / 100 : 0;
                $credit = $entry->type->value === 'credit' ? $entry->amount / 100 : 0;

                $rows->push([
                    'data' => [
                        $trx->posted_at?->format('d/m/Y'),
                        '__str' . $entry->account->code,
                        $entry->account->name ?? '',
                        $entry->description ?? $trx->description,
                        $debit,
                        $credit,
                        '',
                    ],
                ]);
            }
        }

        return [
            'filename'      => "{$company->name} General Journal Voucher {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => 'General Journal Voucher',
            'report_period' => "From {$start->format('d M Y')} to {$end->format('d M Y')}",
            'columns'       => $header,
            'rows'          => $rows->toArray(),
        ];
    }

    public function __buildReportLedgerDetail(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $start = Carbon::parse($this->getFilterState('startDate'));
        $end = Carbon::parse($this->getFilterState('endDate'));
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $header = [
            'Date',
            'Source',
            'Source No.',
            'Description',
            'Debit',
            'Credit',
            'Balance',
        ];

        $transactions = \App\Models\Accounting\Transaction::with(['journalEntries.account', 'bankAccount.account'])
            ->whereBetween('posted_at', [$start, $end])
            ->where('company_id', $company->id)
            ->orderBy('posted_at')
            ->get()
            ->groupBy('bank_account_id');

        $rows = collect();

        foreach ($transactions as $bankAccountId => $groupedTrx) {
            $bankAccount = $groupedTrx->first()?->bankAccount?->account?->name ?? 'Unknown Account';
            $rows->push([
                'data' => ["Bank Account: {$bankAccount}"],
                'style' => ['font-weight' => 'bold'],
            ]);

            $balance = 0;

            foreach ($groupedTrx as $trx) {
                foreach ($trx->journalEntries as $entry) {
                    $debit = $entry->type->value === 'debit' ? $entry->amount : 0;
                    $credit = $entry->type->value === 'credit' ? $entry->amount : 0;

                    $balance += ($debit - $credit);

                    $rows->push([
                        'data' => [
                            $trx->posted_at?->format('d/m/Y'),
                            strtoupper($trx->type->value ?? '-'),
                            $trx->transaction_code,
                            \Illuminate\Support\Str::limit($trx->description, 20),
                            $debit / 100,
                            $credit / 100,
                            $balance / 100,
                        ],
                    ]);
                }
            }
        }

        return [
            'filename'      => "{$company->name} General Ledger Detail {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => 'General Ledger Detail',
            'report_period' => "From {$start->format('d M Y')} to {$end->format('d M Y')}",
            'columns'       => $header,
            'rows'          => $rows->toArray(),
        ];
    }

    public function __buildReportLedgerSummary(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $start = Carbon::parse($this->getFilterState('startDate'));
        $end = Carbon::parse($this->getFilterState('endDate'));
        $now = Carbon::now()->format('Y-m-d_H-i-s');

        $startFormatted = $start->format('d M Y');
        $endFormatted = $end->format('d M Y');

        $header = [
            'Account No',
            'Account Name',
            'Beginning Balance',
            'Change (Debit)',
            'Change (Credit)',
            'Net Change',
            'Ending Balance',
        ];

        // scale: bagi jika amount disimpan dalam satuan terkecil (mis. cents). 
        // Di kode lama kamu menggunakan /100 — kalau tidak perlu, ubah $scale = 1
        $scale = 100;

        // 1) Ambil daftar akun (non-archived)
        $accounts = Account::where('archived', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $accountIds = $accounts->pluck('id')->toArray();

        // 2) Aggregate: beginning balances (je before start)
        $beginning = DB::table('journal_entries')
            ->join('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
            ->select('journal_entries.account_id', DB::raw("
            SUM(
                CASE WHEN journal_entries.type = 'debit' THEN journal_entries.amount
                     WHEN journal_entries.type = 'credit' THEN -journal_entries.amount
                     ELSE 0 END
            ) as balance_before
        "))
            ->whereIn('journal_entries.account_id', $accountIds)
            ->where('journal_entries.company_id', $company->id)
            ->where('transactions.posted_at', '<', $start->toDateString())
            ->groupBy('journal_entries.account_id')
            ->pluck('balance_before', 'account_id')
            ->toArray();

        // 3) Aggregate: period debit totals (sum amounts where type=debit within period)
        $periodDebit = DB::table('journal_entries')
            ->join('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
            ->select('journal_entries.account_id', DB::raw("SUM(CASE WHEN journal_entries.type = 'debit' THEN journal_entries.amount ELSE 0 END) as sum_debit"))
            ->whereIn('journal_entries.account_id', $accountIds)
            ->where('journal_entries.company_id', $company->id)
            ->whereBetween('transactions.posted_at', [$start->toDateString(), $end->toDateString()])
            ->groupBy('journal_entries.account_id')
            ->pluck('sum_debit', 'account_id')
            ->toArray();

        // 4) Aggregate: period credit totals (sum amounts where type=credit within period)
        $periodCredit = DB::table('journal_entries')
            ->join('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
            ->select('journal_entries.account_id', DB::raw("SUM(CASE WHEN journal_entries.type = 'credit' THEN journal_entries.amount ELSE 0 END) as sum_credit"))
            ->whereIn('journal_entries.account_id', $accountIds)
            ->where('journal_entries.company_id', $company->id)
            ->whereBetween('transactions.posted_at', [$start->toDateString(), $end->toDateString()])
            ->groupBy('journal_entries.account_id')
            ->pluck('sum_credit', 'account_id')
            ->toArray();

        $rows = collect();

        $totals = [
            'beginning' => 0.0,
            'debit' => 0.0,
            'credit' => 0.0,
            'net' => 0.0,
            'ending' => 0.0,
        ];

        foreach ($accounts as $account) {
            $aid = $account->id;

            $begRaw = isset($beginning[$aid]) ? (float)$beginning[$aid] : 0.0;
            $chgDebitRaw = isset($periodDebit[$aid]) ? (float)$periodDebit[$aid] : 0.0;
            $chgCreditRaw = isset($periodCredit[$aid]) ? (float)$periodCredit[$aid] : 0.0;

            $netRaw = $chgDebitRaw - $chgCreditRaw;
            $endingRaw = $begRaw + $netRaw;

            // skip accounts with zero movement (optional)
            if ($begRaw == 0 && $chgDebitRaw == 0 && $chgCreditRaw == 0 && $endingRaw == 0) {
                continue;
            }

            // convert scale (e.g. /100)
            $beg = $begRaw / $scale;
            $chgDebit = $chgDebitRaw / $scale;
            $chgCredit = $chgCreditRaw / $scale;
            $net = $netRaw / $scale;
            $ending = $endingRaw / $scale;

            $rows->push([
                'data' => [
                    '__str' . $account->code,
                    $account->name,
                    $beg / 100,
                    $chgDebit / 100,
                    $chgCredit / 100,
                    $net / 100,
                    $ending / 100,
                ],
            ]);

            // accumulate totals (use raw numeric values scaled)
            $totals['beginning'] += $beg;
            $totals['debit'] += $chgDebit;
            $totals['credit'] += $chgCredit;
            $totals['net'] += $net;
            $totals['ending'] += $ending;
        }

        // push totals row
        $rows->push([
            'data' => [
                'TOTAL',
                '',
                $totals['beginning'] / 100,
                $totals['debit'] / 100,
                $totals['credit'] / 100,
                $totals['net'] / 100,
                $totals['ending'] / 100,
            ],
            'style' => [
                'font-weight' => 'bold',
                'color'       => 'rgb(6, 6, 174)',
            ],
        ]);

        return [
            'filename'      => "{$company->name} General Ledger Summary {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => 'General Ledger Summary',
            'report_period' => "From {$startFormatted} to {$endFormatted}",
            'columns'       => $header,
            'rows'          => $rows->toArray(),
        ];
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
            'data' => ["Owner's equity at end of period", $toNumber($report->getOverallTotals()['net_movement']) / 100],
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
                            // 'default'        => 'Perubahan Modal',
                            // 'all'            => 'Financial Ratio',
                            'journal'        => 'General Journal Voucher',
                            'history'        => 'General Ledger History',
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
