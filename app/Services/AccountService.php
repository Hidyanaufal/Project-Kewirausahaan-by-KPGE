<?php

namespace App\Services;

use App\Enums\Accounting\AccountCategory;
use App\Helpers\AuthHelper;
use App\Models\Accounting\Account;
use App\Models\Accounting\Bill;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\Transaction;
use App\Models\Common\Client;
use App\Models\Common\Vendor;
use App\Repositories\Accounting\JournalEntryRepository;
use App\Utilities\Currency\CurrencyAccessor;
use App\ValueObjects\Money;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public function __construct(
        protected JournalEntryRepository $journalEntryRepository
    ) {}

    /**
     * @param  int|null  $projectId
     * @param  int|null  $companyId
     */
    public function getDebitBalance(Account $account, string $startDate, string $endDate, $projectId = null, $companyId = null): Money
    {
        $query = $this->getAccountBalances($startDate, $endDate, [$account->id], $projectId, $companyId)->first();

        return new Money($query->total_debit ?? 0, $account->currency_code);
    }

    /**
     * @param  int|null  $projectId
     * @param  int|null  $companyId
     */
    public function getCreditBalance(Account $account, string $startDate, string $endDate, $projectId = null, $companyId = null): Money
    {
        $query = $this->getAccountBalances($startDate, $endDate, [$account->id], $projectId, $companyId)->first();

        return new Money($query->total_credit ?? 0, $account->currency_code);
    }

    /**
     * @param  int|null  $projectId
     * @param  int|null  $companyId
     */
    public function getNetMovement(Account $account, string $startDate, string $endDate, $projectId = null, $companyId = null): Money
    {
        $query = $this->getAccountBalances($startDate, $endDate, [$account->id], $projectId, $companyId)->first();

        $netMovement = $this->calculateNetMovementByCategory(
            $account->category,
            $query->total_debit ?? 0,
            $query->total_credit ?? 0
        );

        return new Money($netMovement, $account->currency_code);
    }

    /**
     * @param  int|null  $projectId
     * @param  int|null  $companyId
     */
    public function getStartingBalance(Account $account, string $startDate, bool $override = false, $projectId = null, $companyId = null): ?Money
    {
        if ($override === false && $account->category->isNominal()) {
            return null;
        }

        $query = $this->getAccountBalances($startDate, $startDate, [$account->id], $projectId, $companyId)->first();

        return new Money($query->starting_balance ?? 0, $account->currency_code);
    }

    /**
     * @param  int|null  $projectId
     * @param  int|null  $companyId
     */
    public function getEndingBalance(Account $account, string $startDate, string $endDate, $projectId = null, $companyId = null): ?Money
    {
        $query = $this->getAccountBalances($startDate, $endDate, [$account->id], $projectId, $companyId)->first();

        $netMovement = $this->calculateNetMovementByCategory(
            $account->category,
            $query->total_debit ?? 0,
            $query->total_credit ?? 0
        );

        if ($account->category->isNominal()) {
            return new Money($netMovement, $account->currency_code);
        }

        $endingBalance = ($query->starting_balance ?? 0) + $netMovement;

        return new Money($endingBalance, $account->currency_code);
    }

    public function calculateNetMovementByCategory(AccountCategory $category, int $debitBalance, int $creditBalance): int
    {
        if ($category->isNormalDebitBalance()) {
            return $debitBalance - $creditBalance;
        } else {
            return $creditBalance - $debitBalance;
        }
    }

    /**
     * @param  int|null  $projectId
     * @param  int|null  $companyId
     */
    public function getBalances(Account $account, string $startDate, string $endDate, $projectId = null, $companyId = null): array
    {
        $query = $this->getAccountBalances($startDate, $endDate, [$account->id], $projectId, $companyId)->first();

        $needStartingBalances = $account->category->isReal();

        $netMovement = $this->calculateNetMovementByCategory(
            $account->category,
            $query->total_debit ?? 0,
            $query->total_credit ?? 0
        );

        $balances = [
            'debit_balance' => $query->total_debit ?? 0,
            'credit_balance' => $query->total_credit ?? 0,
            'net_movement' => $netMovement,
            'starting_balance' => $needStartingBalances ? ($query->starting_balance ?? 0) : null,
            'ending_balance' => $needStartingBalances
                ? ($query->starting_balance ?? 0) + $netMovement
                : $netMovement, // For nominal accounts, ending balance is just the net movement
        ];

        // Return balances, filtering out any null values
        return array_filter($balances, static fn ($value) => $value !== null);
    }

    /**
     * Transaction details subquery for eager loading.
     *
     * Company / project / project-type scoping matches {@see getAccountBalances} (invoice, bill, or transaction paths).
     *
     * @param  int|null  $projectId
     * @param  int|null  $companyId
     * @param  int|null  $projectTypeId
     */
    public function getTransactionDetailsSubquery(
        string $startDate,
        string $endDate,
        ?string $entityId = null,
        $projectId = null,
        $companyId = null,
        $projectTypeId = null,
    ): Closure {
        $projectId = $projectId !== null && $projectId !== '' ? (int) $projectId : null;
        $companyId = $companyId !== null && $companyId !== '' ? (int) $companyId : null;
        $projectTypeId = $projectTypeId !== null && $projectTypeId !== '' ? (int) $projectTypeId : null;

        return function ($query) use ($startDate, $endDate, $entityId, $projectId, $companyId, $projectTypeId) {
            $query->select(
                'journal_entries.id',
                'journal_entries.account_id',
                'journal_entries.transaction_id',
                'journal_entries.type',
                'journal_entries.amount',
                'journal_entries.description',
                DB::raw("journal_entries.amount * CASE WHEN journal_entries.type = 'debit' THEN 1 ELSE -1 END AS signed_amount")
            )
                ->whereBetween('transactions.posted_at', [$startDate, $endDate])
                ->join('transactions', 'transactions.id', '=', 'journal_entries.transaction_id')
                ->orderBy('transactions.posted_at')
                ->with('transaction:id,type,description,posted_at,is_payment,payeeable_id,payeeable_type');

            if ($entityId) {
                $entityId = (int) $entityId;
                if ($entityId < 0) {
                    $query->whereHas('transaction', function ($query) use ($entityId) {
                        $query->where('payeeable_type', Vendor::class)
                            ->where('payeeable_id', abs($entityId));
                    });
                } else {
                    $query->whereHas('transaction', function ($query) use ($entityId) {
                        $query->where('payeeable_type', Client::class)
                            ->where('payeeable_id', $entityId);
                    });
                }
            }

            $this->applyMultichannelScopeToJournalEntriesQuery($query, $projectId, $companyId, $projectTypeId);
        };
    }

    /**
     * Same company / project / project-type semantics as {@see getAccountBalances}: use the same
     * LEFT JOINs + WHERE OR as the balance query so the planner can reuse index-friendly paths
     * (nested EXISTS per row was very slow on large journal tables).
     *
     * @param  Relation  $query  Eager-load callback receives HasMany, not Eloquent Builder.
     */
    private function applyMultichannelScopeToJournalEntriesQuery(
        Relation $query,
        ?int $projectId,
        ?int $companyId,
        ?int $projectTypeId,
    ): void {
        if ($projectId === null && $companyId === null && $projectTypeId === null) {
            return;
        }

        $this->joinInvoicesBillsProjectsForMultichannelScope($query);
        $this->applyMultichannelScopeAfterDocumentJoins($query, $projectId, $companyId, $projectTypeId);
    }

    /**
     * Invoice / bill / project joins used with {@see applyMultichannelScopeAfterDocumentJoins}.
     */
    private function joinInvoicesBillsProjectsForMultichannelScope(Builder | Relation $query): void
    {
        $query
            ->leftJoin('invoices', function ($join) {
                $join->on('invoices.id', '=', 'transactions.transactionable_id')
                    ->where('transactions.transactionable_type', '=', Invoice::class);
            })
            ->leftJoin('bills', function ($join) {
                $join->on('bills.id', '=', 'transactions.transactionable_id')
                    ->where('transactions.transactionable_type', '=', Bill::class);
            })
            ->leftJoin('projects', function ($join) {
                $join->on('projects.id', '=', DB::raw('COALESCE(invoices.project_id, bills.project_id, transactions.project_id)'));
            });
    }

    /**
     * Restrict rows after invoices, bills, and projects are left-joined (see {@see getAccountBalances}).
     */
    private function applyMultichannelScopeAfterDocumentJoins(Builder | Relation $query, ?int $projectId, ?int $companyId, ?int $projectTypeId): void
    {
        if ($projectId !== null) {
            $query->where(function (Builder $q) use ($projectId) {
                $q->where('invoices.project_id', $projectId)
                    ->orWhere('bills.project_id', $projectId)
                    ->orWhere('transactions.project_id', $projectId);
            });
        }

        if ($companyId !== null) {
            $query->where(function (Builder $q) use ($companyId) {
                $q->where('invoices.company_id', $companyId)
                    ->orWhere('bills.company_id', $companyId)
                    ->orWhere('transactions.company_id', $companyId);
            });
        }

        if ($projectTypeId !== null) {
            $query->where('projects.project_type_id', $projectTypeId);
        }
    }

    /**
     * Main query for account balances.
     *
     * @param  int|null  $projectId
     * @param  int|null  $companyId
     * @param  int|null  $projectTypeId
     * @param  int|string|null  $entityId  Payee filter: positive = Client id, negative = Vendor id
     */
    public function getAccountBalances(
        string $startDate,
        string $endDate,
        array $accountIds = [],
        $projectId = null,
        $companyId = null,
        $projectTypeId = null,
        $entityId = null,
    ): Builder {
        $accountIds = array_map('intval', $accountIds);

        $query = Account::query()
            ->select([
                'accounts.id',
                'accounts.name',
                'accounts.category',
                'accounts.type',
                'accounts.subtype_id',
                'accounts.currency_code',
                'accounts.code',
            ])
            ->addSelect([
                DB::raw("
                COALESCE(
                    CASE
                        WHEN accounts.category IN ('asset', 'expense') THEN
                            SUM(CASE WHEN journal_entries.type = 'debit' AND transactions.posted_at < ? THEN journal_entries.amount ELSE 0 END) -
                            SUM(CASE WHEN journal_entries.type = 'credit' AND transactions.posted_at < ? THEN journal_entries.amount ELSE 0 END)
                        ELSE
                            SUM(CASE WHEN journal_entries.type = 'credit' AND transactions.posted_at < ? THEN journal_entries.amount ELSE 0 END) -
                            SUM(CASE WHEN journal_entries.type = 'debit' AND transactions.posted_at < ? THEN journal_entries.amount ELSE 0 END)
                    END, 0
                ) AS starting_balance
            "),
                DB::raw("
                COALESCE(SUM(
                    CASE WHEN journal_entries.type = 'debit' AND transactions.posted_at BETWEEN ? AND ? THEN journal_entries.amount ELSE 0 END
                ), 0) AS total_debit
            "),
                DB::raw("
                COALESCE(SUM(
                    CASE WHEN journal_entries.type = 'credit' AND transactions.posted_at BETWEEN ? AND ? THEN journal_entries.amount ELSE 0 END
                ), 0) AS total_credit
            "),
            ])
            ->join('journal_entries', 'journal_entries.account_id', '=', 'accounts.id')
            ->join('transactions', function (JoinClause $join) use ($endDate) {
                $join->on('transactions.id', '=', 'journal_entries.transaction_id')
                    ->where('transactions.posted_at', '<=', $endDate);
            })
            ->groupBy([
                'accounts.id',
                'accounts.name',
                'accounts.category',
                'accounts.type',
                'accounts.subtype_id',
                'accounts.currency_code',
                'accounts.code',
            ])
            ->with(['subtype:id,name,inverse_cash_flow']);

        if (AuthHelper::isPrimaryCompany()) {
            $query->withoutGlobalScopes();
        }

        if ($companyId !== null) {
            $query->where('accounts.company_id', $companyId);
        }

        if (! empty($accountIds)) {
            $query->whereIn('accounts.id', $accountIds);
        }

        if ($companyId !== null) {
            $query->where('accounts.company_id', $companyId);
        }

        if ($entityId !== null) {
            $entityId = (int) $entityId;
            if ($entityId < 0) {
                $query->where('transactions.payeeable_type', Vendor::class)
                    ->where('transactions.payeeable_id', abs($entityId));
            } else {
                $query->where('transactions.payeeable_type', Client::class)
                    ->where('transactions.payeeable_id', $entityId);
            }
        }

        // Join invoices & bills for filtering
        if ($projectId !== null || $companyId !== null || $projectTypeId !== null) {
            $this->joinInvoicesBillsProjectsForMultichannelScope($query);

            $this->applyMultichannelScopeAfterDocumentJoins(
                $query,
                $projectId !== null && $projectId !== '' ? (int) $projectId : null,
                $companyId !== null && $companyId !== '' ? (int) $companyId : null,
                $projectTypeId !== null && $projectTypeId !== '' ? (int) $projectTypeId : null,
            );
        }

        $query->addBinding([$startDate, $startDate, $startDate, $startDate, $startDate, $endDate, $startDate, $endDate], 'select');

        return $query;
    }

    /**
     * Cash flow specific account balances (only accounts linked to bank accounts).
     *
     * @param  int|null  $projectId
     * @param  int|null  $companyId
     */
    public function getCashFlowAccountBalances(string $startDate, string $endDate, array $accountIds = [], $projectId = null, $companyId = null): Builder
    {
        $accountIds = array_map('intval', $accountIds);

        $query = $this->getAccountBalances($startDate, $endDate, $accountIds, $projectId, $companyId)
            ->whereExists(function (\Illuminate\Database\Query\Builder $subQuery) {
                $subQuery->select(DB::raw(1))
                    ->from('journal_entries as je')
                    ->join('bank_accounts', 'bank_accounts.account_id', '=', 'je.account_id') // Join bank_accounts on account_id
                    ->whereNotNull('bank_accounts.id') // Ensure there is a linked BankAccount
                    ->whereColumn('je.transaction_id', 'journal_entries.transaction_id');
            });

        return $query;
    }

    /**
     * Total balance for all bank accounts (with optional project/company filters).
     *
     * @param  int|null  $projectId
     * @param  int|null  $companyId
     */
    public function getTotalBalanceForAllBankAccounts(string $startDate, string $endDate, $projectId = null, $companyId = null): Money
    {
        $accountIds = Account::whereHas('bankAccount')
            ->pluck('id')
            ->toArray();

        if (empty($accountIds)) {
            return new Money(0, CurrencyAccessor::getDefaultCurrency());
        }

        $query = DB::table('journal_entries')
            ->join('transactions', function (JoinClause $join) use ($endDate) {
                $join->on('transactions.id', '=', 'journal_entries.transaction_id')
                    ->where('transactions.posted_at', '<=', $endDate);
            })
            ->whereIn('journal_entries.account_id', $accountIds)
            ->selectRaw('
                SUM(CASE
                    WHEN transactions.posted_at < ? AND journal_entries.type = \'debit\' THEN journal_entries.amount
                    WHEN transactions.posted_at < ? AND journal_entries.type = \'credit\' THEN -journal_entries.amount
                    ELSE 0
                END) AS totalStartingBalance,
                SUM(CASE
                    WHEN transactions.posted_at BETWEEN ? AND ? AND journal_entries.type = \'debit\' THEN journal_entries.amount
                    WHEN transactions.posted_at BETWEEN ? AND ? AND journal_entries.type = \'credit\' THEN -journal_entries.amount
                    ELSE 0
                END) AS totalNetMovement
            ', [
                $startDate,
                $startDate,
                $startDate,
                $endDate,
                $startDate,
                $endDate,
            ]);

        if ($projectId !== null || $companyId !== null) {
            $query->leftJoin('invoices', function ($join) {
                $join->on('invoices.id', '=', 'transactions.transactionable_id')
                    ->where('transactions.transactionable_type', '=', Invoice::class);
            })->leftJoin('bills', function ($join) {
                $join->on('bills.id', '=', 'transactions.transactionable_id')
                    ->where('transactions.transactionable_type', '=', Bill::class);
            });

            if ($projectId !== null) {
                $query->where(function ($q) use ($projectId) {
                    $q->where('invoices.project_id', $projectId)
                        ->orWhere('bills.project_id', $projectId)
                        ->orWhere('transactions.project_id', $projectId);
                });
            }

            if ($companyId !== null) {
                $query->where(function ($q) use ($companyId) {
                    $q->where('invoices.company_id', $companyId)
                        ->orWhere('bills.company_id', $companyId)
                        ->orWhere('transactions.company_id', $companyId);
                });
            }
        }

        $result = $query->first();

        $totalBalance = ($result->totalStartingBalance ?? 0) + ($result->totalNetMovement ?? 0);

        return new Money($totalBalance, CurrencyAccessor::getDefaultCurrency());
    }

    /**
     * Starting balance for all bank accounts (with optional filters).
     *
     * @param  int|null  $projectId
     * @param  int|null  $companyId
     */
    public function getStartingBalanceForAllBankAccounts(string $startDate, $projectId = null, $companyId = null): Money
    {
        $accountIds = Account::whereHas('bankAccount')
            ->pluck('id')
            ->toArray();

        if (empty($accountIds)) {
            return new Money(0, CurrencyAccessor::getDefaultCurrency());
        }

        $query = DB::table('journal_entries')
            ->join('transactions', function (JoinClause $join) use ($startDate) {
                $join->on('transactions.id', '=', 'journal_entries.transaction_id')
                    ->where('transactions.posted_at', '<', $startDate);
            })
            ->whereIn('journal_entries.account_id', $accountIds)
            ->selectRaw("
                SUM(CASE
                    WHEN transactions.posted_at < ? AND journal_entries.type = 'debit' THEN journal_entries.amount
                    WHEN transactions.posted_at < ? AND journal_entries.type = 'credit' THEN -journal_entries.amount
                    ELSE 0
                END) AS totalStartingBalance
            ", [
                $startDate,
                $startDate,
            ]);

        if ($projectId !== null || $companyId !== null) {
            $query->leftJoin('invoices', function ($join) {
                $join->on('invoices.id', '=', 'transactions.transactionable_id')
                    ->where('transactions.transactionable_type', '=', Invoice::class);
            })->leftJoin('bills', function ($join) {
                $join->on('bills.id', '=', 'transactions.transactionable_id')
                    ->where('transactions.transactionable_type', '=', Bill::class);
            });

            if ($projectId !== null) {
                $query->where(function ($q) use ($projectId) {
                    $q->where('invoices.project_id', $projectId)
                        ->orWhere('bills.project_id', $projectId)
                        ->orWhere('transactions.project_id', $projectId);
                });
            }

            if ($companyId !== null) {
                $query->where(function ($q) use ($companyId) {
                    $q->where('invoices.company_id', $companyId)
                        ->orWhere('bills.company_id', $companyId)
                        ->orWhere('transactions.company_id', $companyId);
                });
            }
        }

        $result = $query->first();

        return new Money($result->totalStartingBalance ?? 0, CurrencyAccessor::getDefaultCurrency());
    }

    /**
     * Bank account balances helper (uses getAccountBalances).
     *
     * @param  int|null  $projectId
     * @param  int|null  $companyId
     */
    public function getBankAccountBalances(string $startDate, string $endDate, $projectId = null, $companyId = null): Builder | array
    {
        $accountIds = Account::whereHas('bankAccount')
            ->pluck('id')
            ->toArray();

        if (empty($accountIds)) {
            return [];
        }

        return $this->getAccountBalances($startDate, $endDate, $accountIds, $projectId, $companyId);
    }

    public function getEarliestTransactionDate(): string
    {
        $earliestDate = Transaction::min('posted_at');

        return $earliestDate ?? company_today()->toDateTimeString();
    }

    public function getUnpaidClientInvoices(?string $asOfDate = null, $companyId = null): Builder
    {
        $asOfDate = $asOfDate ?? company_today()->toDateString();
        $driver = DB::getDriverName();

        switch ($driver) {
            case 'pgsql':
                $datediff = '(?::date - invoices.due_date::date)';

                break;
            case 'sqlite':
                $datediff = 'JULIANDAY(?) - JULIANDAY(invoices.due_date)';

                break;
            default:
                $datediff = 'DATEDIFF(?, invoices.due_date)';

                break;
        }

        $query = Invoice::query()
            ->select([
                'invoices.id',
                'invoices.client_id',
                'invoices.invoice_number as code',
                'invoices.date',
                'invoices.due_date',
                'invoices.total',
                'invoices.actual_total',
                'invoices.amount_paid',
                'invoices.tax_total',
                'invoices.amount_due',
                'invoices.currency_code',
                DB::raw("{$datediff} as days_overdue"),
            ])
            ->addBinding([$asOfDate], 'select')
            ->unpaid()
            ->where('amount_due', '>', 0);

        if ($companyId !== null) {
            $query->where('invoices.company_id', $companyId);
        }

        return $query;
    }

    public function getUnpaidVendorBills(?string $asOfDate = null, $companyId = null): Builder
    {
        $asOfDate = $asOfDate ?? company_today()->toDateString();
        $driver = DB::getDriverName();

        switch ($driver) {
            case 'pgsql':
                $datediff = '(?::date - bills.due_date::date)';

                break;
            case 'sqlite':
                $datediff = 'JULIANDAY(?) - JULIANDAY(bills.due_date)';

                break;
            default:
                $datediff = 'DATEDIFF(?, bills.due_date)';

                break;
        }

        $query = Bill::query()
            ->select([
                'bills.id',
                'bills.bill_number as code',
                'bills.vendor_id',
                'bills.date',
                'bills.due_date',
                'bills.amount_due',
                'bills.currency_code',
                DB::raw("{$datediff} as days_overdue"),
            ])
            ->addBinding([$asOfDate], 'select')
            ->unpaid()
            ->where('amount_due', '>', 0);

        if ($companyId !== null) {
            $query->where('bills.company_id', $companyId);
        }

        return $query;
    }
}
