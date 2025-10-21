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
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public function __construct(
        protected JournalEntryRepository $journalEntryRepository
    ) {}

    /**
     * @param Account $account
     * @param string $startDate
     * @param string $endDate
     * @param int|null $projectId
     * @param int|null $companyId
     * @return Money
     */
    public function getDebitBalance(Account $account, string $startDate, string $endDate, $projectId = null, $companyId = null): Money
    {
        $query = $this->getAccountBalances($startDate, $endDate, [$account->id], $projectId, $companyId)->first();

        return new Money($query->total_debit ?? 0, $account->currency_code);
    }

    /**
     * @param Account $account
     * @param string $startDate
     * @param string $endDate
     * @param int|null $projectId
     * @param int|null $companyId
     * @return Money
     */
    public function getCreditBalance(Account $account, string $startDate, string $endDate, $projectId = null, $companyId = null): Money
    {
        $query = $this->getAccountBalances($startDate, $endDate, [$account->id], $projectId, $companyId)->first();

        return new Money($query->total_credit ?? 0, $account->currency_code);
    }

    /**
     * @param Account $account
     * @param string $startDate
     * @param string $endDate
     * @param int|null $projectId
     * @param int|null $companyId
     * @return Money
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
     * @param Account $account
     * @param string $startDate
     * @param bool $override
     * @param int|null $projectId
     * @param int|null $companyId
     * @return Money|null
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
     * @param Account $account
     * @param string $startDate
     * @param string $endDate
     * @param int|null $projectId
     * @param int|null $companyId
     * @return Money|null
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
     * @param Account $account
     * @param string $startDate
     * @param string $endDate
     * @param int|null $projectId
     * @param int|null $companyId
     * @return array
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
        return array_filter($balances, static fn($value) => $value !== null);
    }

    /**
     * Transaction details subquery for eager loading.
     *
     * Added optional projectId & companyId filters to restrict transactions if provided.
     *
     * @param string $startDate
     * @param string $endDate
     * @param string|null $entityId
     * @param int|null $projectId
     * @param int|null $companyId
     * @return Closure
     */
    public function getTransactionDetailsSubquery(string $startDate, string $endDate, ?string $entityId = null, $projectId = null, $companyId = null): Closure
    {
        return static function ($query) use ($startDate, $endDate, $entityId, $projectId, $companyId) {
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

            if ($projectId !== null) {
                $query->where('transactions.project_id', $projectId);
            }

            if ($companyId !== null) {
                $query->where('transactions.company_id', $companyId);
            }
        };
    }

    /**
     * Main query for account balances.
     *
     * @param string $startDate
     * @param string $endDate
     * @param array $accountIds
     * @param int|null $projectId
     * @param int|null $companyId
     * @return Builder
     */
    public function getAccountBalances(
        string $startDate,
        string $endDate,
        array $accountIds = [],
        $projectId = null,
        $companyId = null
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

        if (!empty($accountIds)) {
            $query->whereIn('accounts.id', $accountIds);
        }

        // If either projectId or companyId is present, join invoices & bills so we can filter by their columns
        if ($projectId !== null || $companyId !== null) {
            $query
                ->leftJoin('invoices', function ($join) {
                    $join->on('invoices.id', '=', 'transactions.transactionable_id')
                        ->where('transactions.transactionable_type', '=', Invoice::class);
                })
                ->leftJoin('bills', function ($join) {
                    $join->on('bills.id', '=', 'transactions.transactionable_id')
                        ->where('transactions.transactionable_type', '=', Bill::class);
                });

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
        }

        $query->addBinding([$startDate, $startDate, $startDate, $startDate, $startDate, $endDate, $startDate, $endDate], 'select');

        return $query;
    }

    /**
     * Cash flow specific account balances (only accounts linked to bank accounts).
     *
     * @param string $startDate
     * @param string $endDate
     * @param array $accountIds
     * @param int|null $projectId
     * @param int|null $companyId
     * @return Builder
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
     * @param string $startDate
     * @param string $endDate
     * @param int|null $projectId
     * @param int|null $companyId
     * @return Money
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
     * @param string $startDate
     * @param int|null $projectId
     * @param int|null $companyId
     * @return Money
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
     * @param string $startDate
     * @param string $endDate
     * @param int|null $projectId
     * @param int|null $companyId
     * @return Builder|array
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

    public function getUnpaidClientInvoices(?string $asOfDate = null): Builder
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

        return Invoice::query()
            ->select([
                'invoices.id',
                'invoices.client_id',
                'invoices.due_date',
                'invoices.amount_due',
                'invoices.currency_code',
                DB::raw("{$datediff} as days_overdue"),
            ])
            ->addBinding([$asOfDate], 'select')
            ->unpaid()
            ->where('amount_due', '>', 0);
    }

    public function getUnpaidVendorBills(?string $asOfDate = null): Builder
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

        return Bill::query()
            ->select([
                'bills.id',
                'bills.vendor_id',
                'bills.due_date',
                'bills.amount_due',
                'bills.currency_code',
                DB::raw("{$datediff} as days_overdue"),
            ])
            ->addBinding([$asOfDate], 'select')
            ->unpaid()
            ->where('amount_due', '>', 0);
    }
}
