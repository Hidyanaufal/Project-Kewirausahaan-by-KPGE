<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\EnhancedReport;
use App\Contracts\ExportableReport;
use App\DTO\ReportDTO;
use App\Filament\Company\Resources\Accounting\TransactionResource;
use App\Helpers\AuthHelper;
use App\Models\Accounting\Account;
use App\Models\Accounting\Transaction;
use App\Models\Common\Client;
use App\Models\Common\Vendor;
use App\Models\Company;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\Column;
use App\Transformers\AccountTransactionReportTransformer;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\MaxWidth;
use Guava\FilamentClusters\Forms\Cluster;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountTransactions extends BaseReportPage implements EnhancedReport
{
    use WithPagination;

    protected static string $view = 'filament.company.pages.reports.account-transactions';

    protected ReportService $reportService;

    protected ExportService $exportService;

    public function boot(ReportService $reportService, ExportService $exportService): void
    {
        $this->reportService = $reportService;
        $this->exportService = $exportService;
    }

    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return 'max-w-8xl';
    }

    protected function initializeDefaultFilters(): void
    {
        if (empty($this->getFilterState('selectedAccount'))) {
            $this->setFilterState('selectedAccount', 'all');
        }

        if (empty($this->getFilterState('selectedPayee'))) {
            $this->setFilterState('selectedPayee', 'all');
        }
    }

    /**
     * @return array<Column>
     */
    public function getTable(): array
    {
        return [
            Column::make('date')
                ->label('DATE')
                ->markAsDate()
                ->alignment(Alignment::Left),
            Column::make('description')
                ->label('DESCRIPTION')
                ->alignment(Alignment::Left),
            Column::make('debit')
                ->label('DEBIT')
                ->alignment(Alignment::Right),
            Column::make('credit')
                ->label('CREDIT')
                ->alignment(Alignment::Right),
            Column::make('balance')
                ->label('RUNNING BALANCE')
                ->alignment(Alignment::Right),
        ];
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->columns(5)
            ->schema([
                Select::make('selectedAccount')
                    ->label('Account')
                    ->options($this->getAccountOptions())
                    ->selectablePlaceholder(false)
                    ->searchable(),
                $this->getDateRangeFormComponent(),
                Cluster::make([
                    $this->getStartDateFormComponent(),
                    $this->getEndDateFormComponent(),
                ])->extraFieldWrapperAttributes([
                    'class' => 'report-hidden-label',
                ]),
                Select::make('selectedPayee')
                    ->label('Payee')
                    ->options($this->getEntityOptions())
                    ->searchable()
                    ->selectablePlaceholder(false),
                Actions::make([
                    Actions\Action::make('applyFilters')
                        ->label('Update report')
                        ->action('applyFilters')
                        ->keyBindings(['mod+s'])
                        ->button(),
                ])->alignEnd()->verticallyAlignEnd(),
            ]);
    }

    protected function getAccountOptions(): array
    {
        $accounts = Account::query()
            ->limit(10)
            ->get()
            ->groupBy(fn(Account $account) => $account->category->getPluralLabel())
            ->map(fn(Collection $accounts) => $accounts->pluck('name', 'id'))
            ->toArray();

        $allAccountsOption = [
            'All Accounts' => ['all' => 'All Accounts'],
        ];

        return $allAccountsOption + $accounts;
    }

    protected function getEntityOptions(): array
    {
        $clients = Client::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();

        $vendors = Vendor::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn($name, $id) => [-$id => $name])
            ->toArray();

        $allEntitiesOption = [
            'All Payees' => ['all' => 'All Payees'],
        ];

        return $allEntitiesOption + [
            'Clients' => $clients,
            'Vendors' => $vendors,
        ];
    }

    protected function buildReport(array $columns): ReportDTO
    {
        return $this->reportService->buildAccountTransactionsReport(
            startDate: $this->getFormattedStartDate(),
            endDate: $this->getFormattedEndDate(),
            columns: $columns,
            accountId: $this->getFilterState('selectedAccount'),
            entityId: $this->getFilterState('selectedPayee'),
        );
    }

    protected function getTransformer(ReportDTO $reportDTO): ExportableReport
    {
        return new AccountTransactionReportTransformer($reportDTO);
    }

    public function buildReportEnhanced(
        ?string $template = null,
        string $format = 'pdf',
    ): array {
        if ($template == 'all') {
            return $this->__buildReportAllJournal();
        }
        if ($template == 'bank') {
            return $this->__buildReportBankBook();
        }
        if ($template == 'deposit') {
            return $this->__buildReportDeposit();
        }
        return $this->__buildReportDefault();
    }

    public function __buildReportDefault(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());

        $now = now()->format('Y-m-d_H-i-s');

        $toNumber = fn($value) => number_format((float)$value, 2, ',', '.');

        $accounts = Account::query()
            ->where('company_id', $company->id)
            ->with(['journalEntries' => function ($q) {
                $q->select('account_id', DB::raw('SUM(CASE WHEN type = "debit" THEN amount ELSE 0 END) as total_debit'))
                    ->addSelect(DB::raw('SUM(CASE WHEN type = "credit" THEN amount ELSE 0 END) as total_credit'))
                    ->groupBy('account_id');
            }])
            ->get();

        $rows = collect();

        foreach ($accounts as $account) {
            $totalDebit = $account->journalEntries->first()->total_debit ?? 0;
            $totalCredit = $account->journalEntries->first()->total_credit ?? 0;
            $balance = $totalDebit - $totalCredit;

            $rows->push([
                'data' => [
                    '__str' . $account->code,
                    $account->name,
                    $account->type->getLabel() ?? '-',
                    $toNumber($balance / 100),
                ],
            ]);
        }

        $header = [
            'Account No.',
            'Account Name',
            'Account Type',
            'Balance',
        ];

        return [
            'filename'      => "{$company->name} Account List {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => 'Account List',
            'report_period' => 'As of ' . now()->format('d M Y'),
            'columns'       => $header,
            'rows'          => $rows->toArray(),
        ];
    }

    public function __buildReportAllJournal(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());

        $now = now()->format('Y-m-d_H-i-s');

        $toNumber = fn($v) => is_null($v) ? 0 : (float) $v;

        $transactions = Transaction::query()
            ->where('company_id', $company->id)
            ->with([
                'journalEntries.account:id,code,name',
            ])
            ->orderBy('id', 'desc')
            ->get();

        $rows = collect();

        foreach ($transactions as $txn) {
            $txnDate = $txn->posted_at ? Carbon::parse($txn->posted_at)->format('d M Y') : '-';
            $txnDesc = trim("{$txn->description} {$txn->transaction_code} {$txn->notes}");

            // Row Header
            $rows->push([
                'data' => [
                    $txnDate,
                    $txn->transaction_code,
                    $txn->type->getLabel(),
                    $txnDesc,
                ],
                'style' => [
                    'font-weight' => 'bold',
                ],
            ]);

            $totalDebit = 0;
            $totalCredit = 0;

            // Row Detail
            foreach ($txn->journalEntries as $je) {
                $account = $je->account;
                $amount = $toNumber($je->amount / 100 ?? 0);
                $isDebit = $je->type->value === 'debit';

                $debit = $isDebit ? $amount : 0;
                $credit = !$isDebit ? $amount : 0;

                $totalDebit += $debit;
                $totalCredit += $credit;

                $rows->push([
                    'data' => [
                        '__str' . ($account->code ?? '-'),
                        $account->name ?? '-',
                        $debit,
                        $credit,
                    ],
                    'style' => [
                        'padding-left' => '20px',
                    ],
                ]);
            }

            // Row Subtotal
            $rows->push([
                'data' => [
                    '',
                    '',
                    $totalDebit,
                    $totalCredit,
                ],
                'style' => [
                    'font-weight' => 'bold',
                    'color'       => 'rgb(6, 6, 174)',
                ],
            ]);
        }

        $header = [
            'Account No.',
            'Account Name',
            'Debit (Base)',
            'Credit (Base)',
        ];

        return [
            'filename'      => "{$company->name} All Journals {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => 'All Journals',
            'report_period' => 'As of ' . now()->format('d M Y'),
            'columns'       => $header,
            'rows'          => $rows->toArray(),
        ];
    }

    public function __buildReportDeposit(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());

        $now = now()->format('Y-m-d_H-i-s');

        $toNumber = fn($v) => is_null($v) ? 0 : (float) $v;

        $transactions = Transaction::query()
            ->where('company_id', $company->id)
            ->where(function ($query) {
                $query->whereNotNull('member_id')
                    ->where('member_id', '!=', '');
            })
            ->with([
                'journalEntries.account:id,code,name',
                'member'
            ])
            ->orderBy('id', 'desc')
            ->get();

        $rows = collect();
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($transactions as $txn) {
            $txnDate = $txn->posted_at ? Carbon::parse($txn->posted_at)->format('d M Y') : '-';

            // Row Header
            $rows->push([
                'data' => [
                    $txnDate,
                    $txn->transaction_code,
                    $txn->type->getLabel(),
                    '',
                    $txn->member->name

                ],
                'style' => [
                    'font-weight' => 'bold',
                ],
            ]);

            // Row Detail
            foreach ($txn->journalEntries as $je) {
                $account = $je->account;
                $amount = $toNumber($je->amount / 100 ?? 0);
                $isDebit = $je->type->value === 'debit';

                $debit = $isDebit ? $amount : 0;
                $credit = !$isDebit ? $amount : 0;

                $totalDebit += $debit;
                $totalCredit += $credit;

                $rows->push([
                    'data' => [
                        '__str' . ($account->code ?? '-'),
                        $account->name ?? '-',
                        $debit,
                        $credit,
                        ''
                    ],
                    'style' => [
                        'padding-left' => '20px',
                    ],
                ]);
            }
        }

        // Row Total all
        $rows->push([
            'data' => [
                '',
                '',
                $totalDebit,
                $totalCredit,
                ''
            ],
            'style' => [
                'font-weight' => 'bold',
                'color'       => 'rgb(6, 6, 174)',
            ],
        ]);

        $header = [
            'Account No.',
            'Account Name',
            'Debit (Base)',
            'Credit (Base)',
            'Member',
        ];

        return [
            'filename'      => "{$company->name} Member Deposit Report {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => 'Member Deposit Report',
            'report_period' => 'As of ' . now()->format('d M Y'),
            'columns'       => $header,
            'rows'          => $rows->toArray(),
        ];
    }

    public function __buildReportBankBook(): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());

        $companyId = $company->id;

        $now = now()->format('Y-m-d_H-i-s');

        $toNumber = fn($value) => number_format((float)$value / 100, 2, ',', '.');

        $banks = \App\Models\Banking\BankAccount::query()
            ->with(['account', 'transactions' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->whereNotNull('posted_at')
                    ->orderBy('id', 'desc');
            }])
            ->where('company_id', $companyId)
            ->get();

        $rows = collect();

        foreach ($banks as $bank) {
            $rows->push([
                'data' => [
                    '__str' . ($bank->account->code ?? '-'),
                    $bank->account->name ?? '-',
                    'Currency: IDR',
                    '',
                    '',
                    '',
                ],
                'style' => [
                    'font-weight' => 'bold',
                    'color'       => 'rgb(6, 6, 174)',
                ],
            ]);

            $balance = 0;

            foreach ($bank->transactions as $trx) {
                $journalEntries = \App\Models\Accounting\JournalEntry::where('transaction_id', $trx->id)
                    ->where('company_id', $companyId)
                    ->get();

                $increase = $journalEntries->where('type', 'debit')->sum('amount');
                $decrease = $journalEntries->where('type', 'credit')->sum('amount');

                $balance += ($increase - $decrease);

                $rows->push([
                    'data' => [
                        \Carbon\Carbon::parse($trx->posted_at)->format('d M Y'),
                        $trx->transaction_code ?? '-',
                        $trx->description ?? '-',
                        $increase > 0 ? $toNumber($increase) : '',
                        $decrease > 0 ? $toNumber($decrease) : '',
                        $toNumber($balance),
                    ],
                ]);
            }

            $rows->push([
                'data' => ['', '', '', '', '', ''],
            ]);
        }

        $header = [
            'Date',
            'Source No',
            'Description',
            'Increase (Debit)',
            'Decrease (Credit)',
            'Balance',
        ];

        return [
            'filename'      => "{$company->name} Bank Book {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => 'Bank Book',
            'report_period' => 'As of ' . now()->format('d M Y'),
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
                            'default' => 'Account List',
                            'all' => 'All Journal',
                            'bank' => 'Bank Book',
                            'deposit' => 'Member Deposit Report',
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
        return $this->exportService->exportToCsv($this->company, $this->report, $this->getFilterState('startDate'), $this->getFilterState('endDate'));
    }

    public function exportPDF($template = null): StreamedResponse
    {
        return $this->exportService->exportToPdfEnhanced(
            data: $this->buildReportEnhanced(
                template: $template,
                format: 'pdf'
            )
        );
    }

    public function exportXLSX($template = null): BinaryFileResponse
    {
        return $this->exportService->exportToExcelEnhanced(
            data: $this->buildReportEnhanced(
                template: $template,
                format: 'xlsx'
            )
        );
    }

    public function getEmptyStateHeading(): string | Htmlable
    {
        return 'No Transactions Found';
    }

    public function getEmptyStateDescription(): string | Htmlable | null
    {
        return 'Adjust the account or date range, or start by creating a transaction.';
    }

    public function getEmptyStateIcon(): string
    {
        return 'heroicon-o-x-mark';
    }

    public function getEmptyStateActions(): array
    {
        return [
            Action::make('createTransaction')
                ->label('Create transaction')
                ->url(TransactionResource::getUrl()),
        ];
    }

    public function tableHasEmptyState(): bool
    {
        return empty($this->report?->getCategories());
    }
}
