<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\EnhancedReport;
use App\Contracts\ExportableReport;
use App\DTO\ReportDTO;
use App\Enums\Accounting\AccountType;
use App\Filament\Company\Resources\Accounting\TransactionResource;
use App\Filament\Company\Resources\Addons\ProjectResource;
use App\Helpers\AuthHelper;
use App\Models\Accounting\Account;
use App\Models\Accounting\Transaction;
use App\Models\Banking\BankAccount;
use App\Models\Common\Client;
use App\Models\Common\Vendor;
use App\Models\Company;
use App\Services\AccountService;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\Column;
use App\Transformers\AccountTransactionReportTransformer;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\MaxWidth;
use Guava\FilamentClusters\Forms\Cluster;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountTransactions extends BaseReportPage implements EnhancedReport
{
    use WithPagination;

    protected static string $view = 'filament.company.pages.reports.account-transactions';

    protected ReportService $reportService;

    protected ExportService $exportService;

    protected AccountService $accountService;

    /**
     * Account names (case-insensitive) to exclude from Bank Book report.
     *
     * @var array<string>
     */
    private const BANK_BOOK_EXCLUDED_ACCOUNT_NAMES = [
        'kas kecil',
        'kas besar',
        'deposito',
    ];

    public function boot(ReportService $reportService, ExportService $exportService, AccountService $accountService): void
    {
        $this->reportService = $reportService;
        $this->exportService = $exportService;
        $this->accountService = $accountService;
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
            ->columns(6)
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
                ProjectResource::options()
                    ->required(false),
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
            ->get()
            ->groupBy(fn (Account $account) => $account->category->getPluralLabel())
            ->map(fn (Collection $accounts) => $accounts->pluck('name', 'id'))
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
            ->mapWithKeys(fn ($name, $id) => [-$id => $name])
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
        $projectId = $this->getFilterState('project_id');

        return $this->reportService->buildAccountTransactionsReport(
            startDate: $this->getFormattedStartDate(),
            endDate: $this->getFormattedEndDate(),
            columns: $columns,
            accountId: $this->getFilterState('selectedAccount'),
            entityId: $this->getFilterState('selectedPayee'),
            projectId: ($projectId !== null && $projectId !== '') ? (int) $projectId : null,
            companyId: $this->company->id
        );
    }

    protected function getTransformer(ReportDTO $reportDTO): ExportableReport
    {
        return new AccountTransactionReportTransformer($reportDTO);
    }

    public function buildReportEnhanced(
        ?string $template = null,
        string $format = 'pdf',
        ?array $exportFilterOverride = null,
    ): array {
        if ($template == 'all') {
            return $this->__buildReportAllJournal($exportFilterOverride);
        }
        if ($template == 'bank') {
            return $this->__buildReportBankBook($exportFilterOverride);
        }
        if ($template == 'deposit') {
            return $this->__buildReportDeposit($exportFilterOverride);
        }

        return $this->__buildReportDefault($exportFilterOverride);
    }

    /**
     * Form modal export sometimes dehydrates hidden fields as null or ''.
     * Fall back to the same applied report filters the table uses so payee/account stay in sync.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function mergeExportOverrideWithAppliedFilters(array $override): array
    {
        foreach (['startDate', 'endDate', 'selectedAccount', 'selectedPayee', 'project_id'] as $key) {
            $value = $override[$key] ?? null;
            if ($value === null || $value === '') {
                $override[$key] = $this->getFilterState($key);
            }
        }

        return $override;
    }

    /**
     * Resolve export filter: when isActiveExportFiltered, return start/end date and optional account/payee.
     * When $override is provided (e.g. from export form), use those values and normalize dates to avoid timezone/format drift.
     *
     * @param  array<string, mixed>|null  $override  Optional keys: startDate, endDate, selectedAccount, selectedPayee, project_id
     */
    private function getExportFilterParams(?array $override = null): array
    {
        if (! $this->isActiveExportFiltered && $override === null) {
            return ['filtered' => false];
        }

        if ($override !== null) {
            $override = $this->mergeExportOverrideWithAppliedFilters($override);
        }

        if ($override !== null && ! empty($override['startDate']) && ! empty($override['endDate'])) {
            $startDateRaw = $override['startDate'];
            $endDateRaw = $override['endDate'];
            $startDate = Carbon::parse($startDateRaw)->toDateString();
            $endDate = Carbon::parse($endDateRaw)->toDateString();
            $accountId = isset($override['selectedAccount']) && $override['selectedAccount'] !== 'all'
                ? (int) $override['selectedAccount']
                : null;
            $payeeRaw = $override['selectedPayee'];
            $entityId = isset($payeeRaw) && $payeeRaw !== 'all' && $payeeRaw !== ''
                ? (int) $payeeRaw
                : null;
            $projectIdRaw = $override['project_id'] ?? null;
            $projectId = ($projectIdRaw !== null && $projectIdRaw !== '') ? (int) $projectIdRaw : null;

            return [
                'filtered' => true,
                'startDate' => Carbon::parse($startDate)->startOfDay()->toDateTimeString(),
                'endDate' => Carbon::parse($endDate)->endOfDay()->toDateTimeString(),
                'accountId' => $accountId,
                'entityId' => $entityId,
                'projectId' => $projectId,
                'periodLabel' => Carbon::parse($startDate)->toDefaultDateFormat() . ' - ' . Carbon::parse($endDate)->toDefaultDateFormat(),
            ];
        }

        $accountId = $this->getFilterState('selectedAccount');
        $entityId = $this->getFilterState('selectedPayee');
        $projectIdState = $this->getFilterState('project_id');
        $startDate = $this->getFilterState('startDate');
        $endDate = $this->getFilterState('endDate');
        $startDateNormalized = $startDate ? Carbon::parse($startDate)->toDateString() : null;
        $endDateNormalized = $endDate ? Carbon::parse($endDate)->toDateString() : null;

        if ($startDateNormalized === null || $endDateNormalized === null) {
            return ['filtered' => false];
        }

        return [
            'filtered' => true,
            'startDate' => Carbon::parse($startDateNormalized)->startOfDay()->toDateTimeString(),
            'endDate' => Carbon::parse($endDateNormalized)->endOfDay()->toDateTimeString(),
            'accountId' => $accountId !== 'all' ? (int) $accountId : null,
            'entityId' => $entityId !== 'all' ? $entityId : null,
            'projectId' => ($projectIdState !== null && $projectIdState !== '') ? (int) $projectIdState : null,
            'periodLabel' => Carbon::parse($startDateNormalized)->toDefaultDateFormat() . ' - ' . Carbon::parse($endDateNormalized)->toDefaultDateFormat(),
        ];
    }

    /**
     * Build account list rows in parent-then-children order (tree order).
     * When $allowedIds is set, only include accounts whose id is in that set.
     *
     * @param  Collection<int, Account>  $allAccounts  All accounts keyed by id
     * @param  int|null  $parentId  Parent id (null = roots)
     * @param  array<int>|null  $allowedIds  Optional: only include these account ids
     * @return Collection<int, Account>
     */
    private function getAccountsInTreeOrder(Collection $allAccounts, ?int $parentId, ?array $allowedIds = null): Collection
    {
        return $allAccounts
            ->where('parent_id', $parentId)
            ->sortBy('code', SORT_NATURAL)
            ->values()
            ->flatMap(function (Account $account) use ($allAccounts, $allowedIds) {
                $include = $allowedIds === null || in_array($account->id, $allowedIds, true);
                $children = $this->getAccountsInTreeOrder($allAccounts, $account->id, $allowedIds);
                if ($include) {
                    return collect([$account])->concat($children);
                }

                return $children;
            });
    }

    public function __buildReportDefault(?array $exportFilterOverride = null): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $companyId = $company->id;
        $now = now()->format('Y-m-d_H-i-s');
        $toNumber = fn ($value) => number_format((float) $value, 2, ',', '.');
        $params = $this->getExportFilterParams($exportFilterOverride);

        if ($params['filtered']) {
            $startDate = $params['startDate'];
            $endDate = $params['endDate'];
            $accountIds = $params['accountId'] ? [(int) $params['accountId']] : [];
            $entityId = $params['entityId'];
            $reportPeriod = $params['periodLabel'];
            $projectIdForBalances = $params['projectId'] ?? null;
        } else {
            $startDate = $this->accountService->getEarliestTransactionDate();
            $endDate = now()->endOfDay()->format('Y-m-d H:i:s');
            $accountIds = [];
            $entityId = null;
            $reportPeriod = 'As of ' . now()->format('d M Y');
            $projectIdForBalances = null;
        }

        $balanceQueryWithPayee = $this->accountService->getAccountBalances(
            $startDate,
            $endDate,
            $accountIds,
            $projectIdForBalances,
            $companyId,
            null,
            $entityId
        )->orderByRaw('LENGTH(code), code');

        $balanceRowsWithPayee = $balanceQueryWithPayee->get()->keyBy('id');

        $balanceMapPartial = [];
        foreach ($balanceRowsWithPayee as $id => $row) {
            $type = $row->type instanceof AccountType
                ? $row->type
                : AccountType::from($row->type ?? 'current_asset');
            $netMovement = $this->accountService->calculateNetMovementByAccountType(
                $type,
                (int) ($row->total_debit ?? 0),
                (int) ($row->total_credit ?? 0)
            );
            $balanceMapPartial[$id] = ($row->starting_balance ?? 0) + $netMovement;
        }

        $accountIdsNonZero = array_keys(array_filter($balanceMapPartial, fn ($balanceCents) => $balanceCents !== 0));

        if ($entityId !== null && $entityId !== '' && count($accountIdsNonZero) > 0) {
            $balanceQueryFull = $this->accountService->getAccountBalances(
                $startDate,
                $endDate,
                $accountIdsNonZero,
                $projectIdForBalances,
                $companyId,
                null,
                null
            )->orderByRaw('LENGTH(code), code');
            $balanceRowsFull = $balanceQueryFull->get()->keyBy('id');
            $balanceMap = [];
            foreach ($accountIdsNonZero as $id) {
                $rowFull = $balanceRowsFull->get($id);
                $rowPayee = $balanceRowsWithPayee->get($id);
                if ($rowFull === null || $rowPayee === null) {
                    continue;
                }
                $type = $rowPayee->type instanceof AccountType
                    ? $rowPayee->type
                    : AccountType::from($rowPayee->type ?? 'current_asset');
                $periodNetMovement = $this->accountService->calculateNetMovementByAccountType(
                    $type,
                    (int) ($rowPayee->total_debit ?? 0),
                    (int) ($rowPayee->total_credit ?? 0)
                );
                $balanceMap[$id] = ($rowFull->starting_balance ?? 0) + $periodNetMovement;
            }
        } else {
            $balanceMap = $balanceMapPartial;
        }

        $allAccounts = Account::query()
            ->where('company_id', $companyId)
            ->orderByRaw('LENGTH(code), code')
            ->get()
            ->keyBy('id');

        $accountsInTreeOrder = $this->getAccountsInTreeOrder(
            $allAccounts,
            null,
            $accountIdsNonZero
        );

        $accountIdForRunningReport = ($params['accountId'] ?? null) !== null
            ? (string) $params['accountId']
            : 'all';
        $entityIdForRunningReport = isset($params['entityId']) && $params['entityId'] !== null && $params['entityId'] !== ''
            ? (string) (int) $params['entityId']
            : 'all';

        $runningEndingByAccountId = $this->reportService->getAccountTransactionEndingBalancesMinor(
            $params['filtered'] ? $params['startDate'] : $startDate,
            $params['filtered'] ? $params['endDate'] : $endDate,
            $accountIdForRunningReport,
            $entityIdForRunningReport,
            $params['filtered'] ? ($params['projectId'] ?? null) : null,
            $companyId,
        );

        $rows = collect();
        foreach ($accountsInTreeOrder as $account) {
            $endingBalanceCents = $balanceMap[$account->id] ?? 0;
            $runningEndingCents = $runningEndingByAccountId[$account->id] ?? 0;
            $rows->push([
                'data' => [
                    '__str' . $account->code,
                    $account->name,
                    $account->type->getLabel() ?? '-',
                    $toNumber($endingBalanceCents / 100),
                    $toNumber($runningEndingCents / 100),
                ],
            ]);
        }

        $header = [
            'Account No.',
            'Account Name',
            'Account Type',
            'Balance',
            'Running Balance',
        ];

        return [
            'filename' => "{$company->name} Account List {$now}.pdf",
            'company_name' => $company->name,
            'report_title' => 'Account List',
            'report_period' => $reportPeriod,
            'columns' => $header,
            'rows' => $rows->toArray(),
        ];
    }

    public function __buildReportAllJournal(?array $exportFilterOverride = null): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $now = now()->format('Y-m-d_H-i-s');
        $toNumber = fn ($v) => is_null($v) ? 0 : (float) $v;
        $params = $this->getExportFilterParams($exportFilterOverride);

        $query = Transaction::query()
            ->where('company_id', $company->id);

        if ($params['filtered']) {
            $query->whereBetween('posted_at', [$params['startDate'], $params['endDate']]);
            if ($params['entityId'] !== null) {
                $entityId = (int) $params['entityId'];
                if ($entityId < 0) {
                    $query->where('payeeable_type', Vendor::class)->where('payeeable_id', abs($entityId));
                } else {
                    $query->where('payeeable_type', Client::class)->where('payeeable_id', $entityId);
                }
            }
            if ($params['accountId'] !== null) {
                $query->whereHas('journalEntries', fn ($q) => $q->where('account_id', $params['accountId']));
            }
        }

        $transactions = $query
            ->with(['journalEntries.account:id,code,name'])
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
                $credit = ! $isDebit ? $amount : 0;

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
                    'color' => 'rgb(6, 6, 174)',
                ],
            ]);
        }

        $header = [
            'Account No.',
            'Account Name',
            'Debit (Base)',
            'Credit (Base)',
        ];

        $reportPeriod = $params['filtered'] ? $params['periodLabel'] : ('As of ' . now()->format('d M Y'));

        return [
            'filename' => "{$company->name} All Journals {$now}.pdf",
            'company_name' => $company->name,
            'report_title' => 'All Journals',
            'report_period' => $reportPeriod,
            'columns' => $header,
            'rows' => $rows->toArray(),
        ];
    }

    public function __buildReportDeposit(?array $exportFilterOverride = null): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $now = now()->format('Y-m-d_H-i-s');
        $toNumber = fn ($v) => is_null($v) ? 0 : (float) $v;
        $params = $this->getExportFilterParams($exportFilterOverride);

        $query = Transaction::query()
            ->where('company_id', $company->id)
            ->where(function ($query) {
                $query->whereNotNull('member_id')
                    ->where('member_id', '!=', '');
            });

        if ($params['filtered']) {
            $query->whereBetween('posted_at', [$params['startDate'], $params['endDate']]);
            if ($params['entityId'] !== null) {
                $entityId = (int) $params['entityId'];
                if ($entityId < 0) {
                    $query->where('payeeable_type', Vendor::class)->where('payeeable_id', abs($entityId));
                } else {
                    $query->where('payeeable_type', Client::class)->where('payeeable_id', $entityId);
                }
            }
            if ($params['accountId'] !== null) {
                $query->whereHas('journalEntries', fn ($q) => $q->where('account_id', $params['accountId']));
            }
        }

        $transactions = $query
            ->with(['journalEntries.account:id,code,name', 'member'])
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
                    $txn->member->name,

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
                $credit = ! $isDebit ? $amount : 0;

                $totalDebit += $debit;
                $totalCredit += $credit;

                $rows->push([
                    'data' => [
                        '__str' . ($account->code ?? '-'),
                        $account->name ?? '-',
                        $debit,
                        $credit,
                        '',
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
                '',
            ],
            'style' => [
                'font-weight' => 'bold',
                'color' => 'rgb(6, 6, 174)',
            ],
        ]);

        $header = [
            'Account No.',
            'Account Name',
            'Debit (Base)',
            'Credit (Base)',
            'Member',
        ];

        $reportPeriod = $params['filtered'] ? $params['periodLabel'] : ('As of ' . now()->format('d M Y'));

        return [
            'filename' => "{$company->name} Member Deposit Report {$now}.pdf",
            'company_name' => $company->name,
            'report_title' => 'Member Deposit Report',
            'report_period' => $reportPeriod,
            'columns' => $header,
            'rows' => $rows->toArray(),
        ];
    }

    private function isBankBookExcludedAccount(BankAccount $bank): bool
    {
        $account = $bank->account;
        if (! $account?->name) {
            return false;
        }
        $nameLower = strtolower($account->name);
        foreach (self::BANK_BOOK_EXCLUDED_ACCOUNT_NAMES as $excluded) {
            if (str_contains($nameLower, $excluded) || $nameLower === $excluded) {
                return true;
            }
        }

        return false;
    }

    public function __buildReportBankBook(?array $exportFilterOverride = null): array
    {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $companyId = $company->id;
        $now = now()->format('Y-m-d_H-i-s');
        $toNumber = fn ($value) => number_format((float) $value / 100, 2, ',', '.');
        $params = $this->getExportFilterParams($exportFilterOverride);

        $bankQuery = \App\Models\Banking\BankAccount::query()
            ->where('company_id', $companyId);

        if ($params['filtered'] && $params['accountId'] !== null) {
            $bankQuery->where('account_id', $params['accountId']);
        }

        $banks = $bankQuery->with([
            'account',
            'transactions' => function ($q) use ($companyId, $params) {
                $q->where('company_id', $companyId)
                    ->whereNotNull('posted_at');
                if ($params['filtered']) {
                    $q->whereBetween('posted_at', [$params['startDate'], $params['endDate']]);
                }
                if ($params['entityId'] !== null) {
                    $entityId = (int) $params['entityId'];
                    if ($entityId < 0) {
                        $q->where('payeeable_type', Vendor::class)->where('payeeable_id', abs($entityId));
                    } else {
                        $q->where('payeeable_type', Client::class)->where('payeeable_id', $entityId);
                    }
                }
                $q->orderBy('id', 'desc');
            },
        ])->get();

        $banks = $banks->filter(fn (BankAccount $bank) => ! $this->isBankBookExcludedAccount($bank));

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
                    'color' => 'rgb(6, 6, 174)',
                ],
            ]);

            $balance = 0;

            foreach ($bank->transactions as $trx) {
                $journalEntries = \App\Models\Accounting\JournalEntry::where('transaction_id', $trx->id)
                    ->where('company_id', $companyId)
                    ->where('account_id', $bank->account_id)
                    ->get();

                $increase = $journalEntries->filter(fn ($je) => $je->type->value === 'debit')->sum('amount');
                $decrease = $journalEntries->filter(fn ($je) => $je->type->value === 'credit')->sum('amount');

                $balance += ($increase - $decrease);

                $rows->push([
                    'data' => [
                        \Carbon\Carbon::parse($trx->posted_at)->format('d M Y'),
                        $trx->transaction_code ?? '-',
                        $trx->description ?? '-',
                        $toNumber($increase),
                        $toNumber($decrease),
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

        $reportPeriod = $params['filtered'] ? $params['periodLabel'] : ('As of ' . now()->format('d M Y'));

        return [
            'filename' => "{$company->name} Bank Book {$now}.pdf",
            'company_name' => $company->name,
            'report_title' => 'Bank Book',
            'report_period' => $reportPeriod,
            'columns' => $header,
            'rows' => $rows->toArray(),
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
                    Hidden::make('startDate')->default(fn () => $this->getFilterState('startDate')),
                    Hidden::make('endDate')->default(fn () => $this->getFilterState('endDate')),
                    Hidden::make('selectedAccount')->default(fn () => $this->getFilterState('selectedAccount')),
                    Hidden::make('selectedPayee')->default(fn () => $this->getFilterState('selectedPayee')),
                    Hidden::make('project_id')->default(fn () => $this->getFilterState('project_id')),
                ])
                ->action(function (array $data) {
                    $format = $data['format'];
                    $template = $data['template'];
                    // Same keys as mergeExportOverrideWithAppliedFilters; empty hidden values are fixed in getExportFilterParams.
                    $filterParams = [
                        'startDate' => $data['startDate'] ?? null,
                        'endDate' => $data['endDate'] ?? null,
                        'selectedAccount' => $data['selectedAccount'] ?? null,
                        'selectedPayee' => $data['selectedPayee'] ?? null,
                        'project_id' => $data['project_id'] ?? null,
                    ];

                    if ($format == 'xlsx') {
                        return $this->exportXLSX($template, $filterParams);
                    }

                    if ($format == 'pdf') {
                        return $this->exportPDF($template, $filterParams);
                    }
                }),
        ];
    }

    public function exportCSV(): StreamedResponse
    {
        return $this->exportService->exportToCsv($this->company, $this->report, $this->getFilterState('startDate'), $this->getFilterState('endDate'));
    }

    public function exportPDF($template = null, ?array $filterParams = null): StreamedResponse
    {
        return $this->exportService->exportToPdfEnhanced(
            data: $this->buildReportEnhanced(
                template: $template,
                format: 'pdf',
                exportFilterOverride: $filterParams
            )
        );
    }

    public function exportXLSX($template = null, ?array $filterParams = null): BinaryFileResponse
    {
        return $this->exportService->exportToExcelEnhanced(
            data: $this->buildReportEnhanced(
                template: $template,
                format: 'xlsx',
                exportFilterOverride: $filterParams
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
