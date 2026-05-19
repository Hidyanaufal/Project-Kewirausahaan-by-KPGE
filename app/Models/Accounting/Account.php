<?php

namespace App\Models\Accounting;

use App\Concerns\Blamable;
use App\Concerns\CompanyOwned;
use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountDefaultKey;
use App\Enums\Accounting\AccountType;
use App\Facades\Accounting;
use App\Models\AccountDefault;
use App\Models\Banking\BankAccount;
use App\Models\Setting\Currency;
use App\Observers\AccountObserver;
use App\Utilities\Currency\CurrencyAccessor;
use App\Scopes\CurrentCompanyScope;
use Database\Factories\Accounting\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[ObservedBy(AccountObserver::class)]
class Account extends Model
{
    use Blamable;
    use CompanyOwned;
    use HasFactory;

    protected $table = 'accounts';

    protected $fillable = [
        'company_id',
        'subtype_id',
        'parent_id',
        'category',
        'type',
        'code',
        'name',
        'currency_code',
        'description',
        'archived',
        'default',
        'created_by',
        'updated_by',
        'bank_code'
    ];

    protected $casts = [
        'category' => AccountCategory::class,
        'type' => AccountType::class,
        'archived' => 'boolean',
        'default' => 'boolean',
    ];

    public function subtype(): BelongsTo
    {
        return $this->belongsTo(AccountSubtype::class, 'subtype_id')->withoutGlobalScopes();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(__CLASS__, 'parent_id')
            ->whereKeyNot($this->getKey());
    }

    public function children(): HasMany
    {
        return $this->hasMany(__CLASS__, 'parent_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function bankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class, 'account_id');
    }

    public function adjustment(): HasOne
    {
        return $this->hasOne(Adjustment::class, 'account_id');
    }

    #[Scope]
    protected function budgetable(Builder $query): Builder
    {
        return $query->whereIn('category', [
            AccountCategory::Revenue,
            AccountCategory::Expense,
        ])
            ->whereNotIn('type', [
                AccountType::ContraRevenue,
                AccountType::ContraExpense,
                AccountType::UncategorizedRevenue,
                AccountType::UncategorizedExpense,
            ])
            ->whereDoesntHave('subtype', function (Builder $query) {
                $query->whereIn('name', [
                    'Receivables',
                    'Input Tax Recoverable',
                ]);
            })
            ->whereNotIn('name', [
                'Gain on Foreign Exchange',
                'Loss on Foreign Exchange',
            ])
            ->where('currency_code', CurrencyAccessor::getDefaultCurrency());
    }

    #[Scope]
    protected function withLastTransactionDate(Builder $query): Builder
    {
        return $query->addSelect([
            'last_transaction_date' => JournalEntry::select(DB::raw('MAX(transactions.posted_at)'))
                ->join('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
                ->whereColumn('journal_entries.account_id', 'accounts.id')
                ->limit(1),
        ]);
    }

    protected function endingBalance(): Attribute
    {
        return Attribute::get(function () {
            $company = $this->company;
            $fiscalYearStart = $company->locale->fiscalYearStartDate();
            $fiscalYearEnd = $company->locale->fiscalYearEndDate();

            return Accounting::getEndingBalance($this, $fiscalYearStart, $fiscalYearEnd);
        });
    }

    public function isUncategorized(): bool
    {
        return $this->type->isUncategorized();
    }

    /**
     * Whether this account is a parent/header in the chart of accounts (has child accounts).
     * Parent balances must not be aggregated alongside children to avoid double-counting in reports.
     */
    public function isParentAccount(): bool
    {
        if ($this->relationLoaded('children')) {
            if ($this->children->isNotEmpty()) {
                return true;
            }
        } elseif ($this->children()->exists()) {
            return true;
        }

        $code = trim((string) $this->code);

        if ($code === '') {
            return false;
        }

        return static::query()
            ->where('company_id', $this->company_id)
            ->whereKeyNot($this->getKey())
            ->where(function (Builder $query) use ($code) {
                $query->where('code', 'like', $code . '.%')
                    ->orWhere('code', 'like', $code . '-%');

                if (ctype_digit($code) && strlen($code) >= 3) {
                    $query->orWhere(function (Builder $nested) use ($code) {
                        $nested->where('code', 'like', $code . '%')
                            ->whereRaw('LENGTH(code) > ?', [strlen($code)]);
                    });
                }
            })
            ->exists();
    }

    /**
     * Account IDs that should be excluded from balance-sheet category totals (parent/header accounts).
     *
     * @return Collection<int, int>
     */
    public static function parentAccountIdsForCompany(int $companyId): Collection
    {
        return static::query()
            ->where('company_id', $companyId)
            ->get(['id', 'code', 'company_id'])
            ->filter(static fn (self $account): bool => $account->isParentAccount())
            ->pluck('id');
    }

    /**
     * Account IDs excluded from balance sheet totals: parent/header accounts and system clearing accounts
     * (e.g. Open Balance Equity) whose balances are already reflected in other balance sheet lines.
     *
     * @return Collection<int, int>
     */
    public static function balanceSheetExcludedAccountIdsForCompany(int $companyId): Collection
    {
        $excluded = static::parentAccountIdsForCompany($companyId);

        $clearingAccountIds = AccountDefault::query()
            ->where('company_id', $companyId)
            ->whereIn('key', [
                AccountDefaultKey::OpenBalance,
            ])
            ->pluck('account_id');

        return $excluded->merge($clearingAccountIds)->unique()->values();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'account_id');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'account_id');
    }

    protected static function findAccountByNameOrFail(AccountDefaultKey $accountDefaultKey, ?int $companyId = null): self
    {
        try {
            // Find AccountDefault by key and company (if provided)
            $accountDefaultQuery = AccountDefault::where('key', $accountDefaultKey->value);
            if ($companyId) {
                // Saat company_id eksplisit diberikan, pastikan lookup tidak ikut ke-intersect global scope
                // `CurrentCompanyScope` (yang berbasis session current_company_id).
                $accountDefaultQuery = $accountDefaultQuery->withoutGlobalScope(CurrentCompanyScope::class);
            }

            $accountDefault = $accountDefaultQuery
                ->when($companyId, fn($q) => $q->where('company_id', $companyId))
                ->first();

            if (! $accountDefault) {
                throw new \RuntimeException(sprintf(
                    'The default account for COA key "%s" is not configured. Please set up the default account in the chart of accounts settings.',
                    $accountDefaultKey->value
                ));
            }

            // Find the Account linked to the AccountDefault
            $accountQuery = self::where('id', $accountDefault->account_id);
            if ($companyId) {
                $accountQuery = $accountQuery->withoutGlobalScope(CurrentCompanyScope::class);
            }

            $account = $accountQuery
                ->when($companyId, fn($q) => $q->where('company_id', $companyId))
                ->first();

            if (! $account) {
                throw new \RuntimeException(sprintf(
                    'The account linked to COA key "%s" (ID: %d) could not be found. Please verify your chart of accounts configuration.',
                    $accountDefaultKey->value,
                    $accountDefault->account_id
                ));
            }

            return $account;
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                'Failed to retrieve account for COA key "%s"%s. Please ensure that the default account is properly configured in your chart of accounts. Details: %s',
                $accountDefaultKey->value,
                $companyId ? " (Company ID: {$companyId})" : '',
                $e->getMessage()
            ), 0, $e);
        }
    }

    public static function getOpeningBalanceAccount(?int $companyId = null): self
    {
        return self::findAccountByNameOrFail(AccountDefaultKey::OpenBalance, $companyId);
    }

    public static function getAccountsReceivableAccount(?int $companyId = null): self
    {
        return self::findAccountByNameOrFail(AccountDefaultKey::AccountsReceivable, $companyId);
    }

    public static function getAccountsPayableAccount(?int $companyId = null): self
    {
        return self::findAccountByNameOrFail(AccountDefaultKey::AccountsPayable, $companyId);
    }

    public static function getSalesDiscountAccount(?int $companyId = null): self
    {
        return self::findAccountByNameOrFail(AccountDefaultKey::SalesDiscount, $companyId);
    }

    public static function getPurchaseDiscountAccount(?int $companyId = null): self
    {
        return self::findAccountByNameOrFail(AccountDefaultKey::PurchaseDiscount, $companyId);
    }

    public static function getAccountsPPh23(?int $companyId = null): self
    {
        return self::findAccountByNameOrFail(AccountDefaultKey::PPh23, $companyId);
    }

    public static function getAccountsPPh23Payable(?int $companyId = null): self
    {
        return self::findAccountByNameOrFail(AccountDefaultKey::PPh23Payable, $companyId);
    }

    public static function getAccountsPPn(?int $companyId = null): self
    {
        return self::findAccountByNameOrFail(AccountDefaultKey::PPn, $companyId);
    }

    public static function getAccountsWapu(?int $companyId = null): self
    {
        return self::findAccountByNameOrFail(AccountDefaultKey::PPnWapu, $companyId);
    }

    public static function getAccountsWapuIfConfigured(?int $companyId = null): ?self
    {
        if (! $companyId) {
            return null;
        }

        $accountDefault = AccountDefault::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->where('company_id', $companyId)
            ->where('key', AccountDefaultKey::PPnWapu->value)
            ->first();

        if (! $accountDefault) {
            return null;
        }

        return self::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->where('company_id', $companyId)
            ->where('id', $accountDefault->account_id)
            ->first();
    }

    public static function getAccountsProfit(?int $companyId = null): self
    {
        return self::findAccountByNameOrFail(AccountDefaultKey::Profit, $companyId);
    }

    protected static function newFactory(): Factory
    {
        return AccountFactory::new();
    }
}
