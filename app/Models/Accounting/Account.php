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
     * Set-based: parent_id children + in-memory code-prefix detection (no N+1 EXISTS per account).
     *
     * @return Collection<int, int>
     */
    public static function parentAccountIdsForCompany(int $companyId): Collection
    {
        $accounts = static::query()
            ->where('company_id', $companyId)
            ->get(['id', 'code', 'parent_id', 'name']);

        return static::parentAccountIdsFromCollection($accounts);
    }

    /**
     * Detect parent/header account IDs from an already-loaded account collection.
     *
     * @param  Collection<int, self>  $accounts
     * @return Collection<int, int>
     */
    public static function parentAccountIdsFromCollection(Collection $accounts): Collection
    {
        $parentIds = $accounts
            ->pluck('parent_id')
            ->filter()
            ->unique()
            ->values();

        $byCode = [];
        foreach ($accounts as $account) {
            $code = trim((string) $account->code);
            if ($code === '') {
                continue;
            }
            $byCode[$code][] = (int) $account->id;
        }

        $codes = array_keys($byCode);
        $prefixParentIds = [];

        foreach ($codes as $code) {
            $isParent = false;

            foreach ($codes as $otherCode) {
                if ($otherCode === $code) {
                    continue;
                }

                if (str_starts_with($otherCode, $code . '.') || str_starts_with($otherCode, $code . '-')) {
                    $isParent = true;
                    break;
                }

                if (
                    ctype_digit($code)
                    && strlen($code) >= 3
                    && str_starts_with($otherCode, $code)
                    && strlen($otherCode) > strlen($code)
                ) {
                    $isParent = true;
                    break;
                }
            }

            if ($isParent) {
                foreach ($byCode[$code] as $id) {
                    $prefixParentIds[] = $id;
                }
            }
        }

        return $parentIds
            ->merge($prefixParentIds)
            ->unique()
            ->values();
    }

    /**
     * System clearing accounts (Open Balance Equity / Laba Tahun Berjalan) — parkir import / computed equity.
     * Ditampilkan di COA, tapi disembunyikan dari baris laporan dan tidak masuk total neraca.
     *
     * @return Collection<int, int>
     */
    public static function balanceSheetClearingAccountIdsForCompany(int $companyId): Collection
    {
        return AccountDefault::query()
            ->where('company_id', $companyId)
            ->whereIn('key', [
                AccountDefaultKey::OpenBalance,
                AccountDefaultKey::CurrentYearEarnings,
            ])
            ->pluck('account_id')
            ->unique()
            ->values();
    }

    /**
     * Legacy RETAINED EARNING IDs when Laba Ditahan (atau RE lain yang dipilih) sudah ada —
     * hindari dobel equity di laporan.
     *
     * @param  Collection<int, self>|null  $accounts
     * @return Collection<int, int>
     */
    public static function duplicateRetainedEarningsAccountIdsForCompany(
        int $companyId,
        ?Collection $accounts = null,
    ): Collection {
        $accounts ??= static::query()
            ->where('company_id', $companyId)
            ->get(['id', 'code', 'parent_id', 'name']);

        $retainedLike = $accounts
            ->filter(function (self $account): bool {
                $normalized = mb_strtolower(trim((string) $account->name));

                return in_array($normalized, ['retained earning', 'retained earnings', 'laba ditahan'], true)
                    || str_contains($normalized, 'retained earn')
                    || str_contains($normalized, 'laba ditahan');
            })
            ->values();

        if ($retainedLike->count() <= 1) {
            return collect();
        }

        $preferred = $retainedLike->first(
            fn (self $account): bool => str_contains(mb_strtolower((string) $account->name), 'laba ditahan')
        ) ?? $retainedLike->first();

        return $retainedLike
            ->reject(fn (self $account): bool => $account->id === $preferred->id)
            ->pluck('id')
            ->values();
    }

    /**
     * Akun yang disembunyikan dari baris Balance Sheet: clearing (OBE/CYE) + RETAINED EARNING legacy.
     *
     * @return Collection<int, int>
     */
    public static function balanceSheetHiddenLineAccountIdsForCompany(int $companyId): Collection
    {
        return static::balanceSheetClearingAccountIdsForCompany($companyId)
            ->merge(static::duplicateRetainedEarningsAccountIdsForCompany($companyId))
            ->unique()
            ->values();
    }

    /**
     * Account IDs excluded from balance sheet totals: parent/header accounts and system clearing accounts
     * (e.g. Open Balance Equity) whose balances are already reflected in other balance sheet lines.
     *
     * @return Collection<int, int>
     */
    public static function balanceSheetExcludedAccountIdsForCompany(int $companyId): Collection
    {
        $accounts = static::query()
            ->where('company_id', $companyId)
            ->get(['id', 'code', 'parent_id', 'name']);

        $excluded = static::parentAccountIdsFromCollection($accounts);

        return $excluded
            ->merge(static::balanceSheetClearingAccountIdsForCompany($companyId))
            ->merge(static::duplicateRetainedEarningsAccountIdsForCompany($companyId, $accounts))
            ->unique()
            ->values();
    }

    /**
     * Account IDs hidden from Trial Balance: parents + clearing (OBE / CYE)
     * + akun RETAINED EARNING legacy jika sudah ada Laba Ditahan (hindari dobel equity).
     *
     * @return Collection<int, int>
     */
    public static function trialBalanceExcludedAccountIdsForCompany(int $companyId): Collection
    {
        $accounts = static::query()
            ->where('company_id', $companyId)
            ->get(['id', 'code', 'parent_id', 'name']);

        $excluded = static::parentAccountIdsFromCollection($accounts);

        return $excluded
            ->merge(static::balanceSheetClearingAccountIdsForCompany($companyId))
            ->merge(static::duplicateRetainedEarningsAccountIdsForCompany($companyId, $accounts))
            ->unique()
            ->values();
    }

    /**
     * Whether computed retained earnings (Revenue − Expense) should be added to balance sheet equity totals.
     * When a dedicated retained earnings GL account exists, cumulative P&L is already reflected there and
     * adding the computed figure would double-count.
     *
     * The mapped Laba Tahun Berjalan account is excluded from this check so its own COA code (e.g. 3200.x)
     * does not suppress the computed line.
     */
    public static function shouldAddComputedRetainedEarningsToBalanceSheet(int $companyId): bool
    {
        return static::dedicatedRetainedEarningsGlAccountQuery($companyId)->doesntExist();
    }

    /**
     * Whether a computed equity line (Laba Tahun Berjalan or Retained Earnings) should be injected.
     * When Laba Tahun Berjalan is mapped via Account Default, always inject regardless of GL retained earnings.
     * When dedicated RE GL exists but CYE is not mapped, still inject if the company has nominal (P&L)
     * accounts — prior RE lives in the GL account while YTD net income must appear as CYE.
     */
    public static function shouldInjectComputedEquityLineOnBalanceSheet(int $companyId): bool
    {
        if (static::getCurrentYearEarningsAccount($companyId) !== null) {
            return true;
        }

        if (static::shouldAddComputedRetainedEarningsToBalanceSheet($companyId)) {
            return true;
        }

        return static::companyHasNominalAccounts($companyId);
    }

    public static function shouldShowComputedCurrentYearEarningsOnBalanceSheet(int $companyId): bool
    {
        return static::getCurrentYearEarningsAccount($companyId) !== null
            || (
                ! static::shouldAddComputedRetainedEarningsToBalanceSheet($companyId)
                && static::companyHasNominalAccounts($companyId)
            );
    }

    /**
     * Whether the company has any revenue/expense leaf accounts (used to decide CYE inject fallback).
     */
    public static function companyHasNominalAccounts(int $companyId): bool
    {
        return static::query()
            ->where('company_id', $companyId)
            ->whereIn('category', [AccountCategory::Revenue, AccountCategory::Expense])
            ->exists();
    }

    /**
     * @return Builder<self>
     */
    protected static function dedicatedRetainedEarningsGlAccountQuery(int $companyId): Builder
    {
        $ltjAccountId = static::currentYearEarningsAccountIdForCompany($companyId);

        return static::query()
            ->where('company_id', $companyId)
            ->where('category', AccountCategory::Equity)
            ->when($ltjAccountId, fn (Builder $query) => $query->where('id', '!=', $ltjAccountId))
            ->where(function (Builder $query): void {
                $query->where('code', 'like', '3200%')
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%retained earn%']);
            });
    }

    public static function getCurrentYearEarningsAccount(?int $companyId = null): ?self
    {
        if (! $companyId) {
            return null;
        }

        $accountDefault = AccountDefault::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->where('company_id', $companyId)
            ->where('key', AccountDefaultKey::CurrentYearEarnings->value)
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

    public static function currentYearEarningsAccountIdForCompany(int $companyId): ?int
    {
        return static::getCurrentYearEarningsAccount($companyId)?->id;
    }

    /**
     * @return array{label: string, code: string, typeName: string}
     */
    public static function resolveComputedEquityLineForBalanceSheet(?int $companyId): array
    {
        $ltjAccount = $companyId !== null ? static::getCurrentYearEarningsAccount($companyId) : null;

        if ($ltjAccount !== null) {
            return [
                'label' => $ltjAccount->name,
                'code' => $ltjAccount->code,
                'typeName' => $ltjAccount->name,
            ];
        }

        // Dedicated RE GL holds prior retained earnings; label YTD net income as Laba Tahun Berjalan.
        if (
            $companyId !== null
            && ! static::shouldAddComputedRetainedEarningsToBalanceSheet($companyId)
            && static::companyHasNominalAccounts($companyId)
        ) {
            return [
                'label' => 'Laba Tahun Berjalan',
                'code' => 'CYE',
                'typeName' => 'Laba Tahun Berjalan',
            ];
        }

        return [
            'label' => 'Retained Earnings',
            'code' => 'RE',
            'typeName' => 'Retained Earnings',
        ];
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
