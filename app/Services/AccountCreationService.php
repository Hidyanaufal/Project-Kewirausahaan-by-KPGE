<?php

namespace App\Services;

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryType;
use App\Helpers\AccountTypeHelper;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
use App\Utilities\Currency\CurrencyAccessor;
use InvalidArgumentException;

class AccountCreationService
{
    public function createLeafAccount(
        int $companyId,
        string $name,
        string $code,
        string $accountTypeLabel,
        ?string $parentCodeHint = null,
        ?int $parentAccountId = null,
    ): Account {
        if (Account::query()->where('company_id', $companyId)->where('code', $code)->exists()) {
            throw new InvalidArgumentException("Account code already exists: {$code}");
        }

        $accountType = AccountTypeHelper::map($accountTypeLabel, $name);
        if ($accountType === null) {
            throw new InvalidArgumentException("Unknown account type: {$accountTypeLabel}");
        }

        $subtype = $this->resolveSubtype($companyId, $accountTypeLabel, $name);

        $parentId = $parentAccountId ?? $this->resolveParentId($companyId, $code, $parentCodeHint);

        return Account::create([
            'company_id' => $companyId,
            'subtype_id' => $subtype->id,
            'parent_id' => $parentId,
            'category' => $accountType->getCategory(),
            'type' => $accountType,
            'code' => $code,
            'name' => $name,
            'currency_code' => CurrencyAccessor::getDefaultCurrency(),
            'archived' => false,
            'default' => false,
        ]);
    }

    public function createParentAccount(
        int $companyId,
        string $name,
        string $code,
        string $accountTypeLabel,
    ): Account {
        $existing = Account::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->first();

        if ($existing) {
            return $existing;
        }

        $accountType = AccountTypeHelper::map($accountTypeLabel, $name);
        if ($accountType === null) {
            throw new InvalidArgumentException("Unknown account type: {$accountTypeLabel}");
        }

        $subtype = $this->resolveSubtype($companyId, $accountTypeLabel, $name);

        return Account::create([
            'company_id' => $companyId,
            'subtype_id' => $subtype->id,
            'parent_id' => null,
            'category' => $accountType->getCategory(),
            'type' => $accountType,
            'code' => $code,
            'name' => $name,
            'currency_code' => CurrencyAccessor::getDefaultCurrency(),
            'archived' => false,
            'default' => false,
        ]);
    }

    public function createLeafAccountUnderParent(
        int $companyId,
        string $name,
        string $code,
        string $accountTypeLabel,
        Account $parentAccount,
    ): Account {
        return $this->createLeafAccount(
            $companyId,
            $name,
            $code,
            $accountTypeLabel,
            parentCodeHint: null,
            parentAccountId: $parentAccount->id,
        );
    }

    /**
     * Pastikan akun penyisihan/allowance bertipe ContraAsset (normal credit).
     * Dipakai saat import ke akun existing yang masih CurrentAsset (label AR di Excel).
     *
     * @return bool true jika klasifikasi diubah
     */
    public function ensureAllowanceContraAssetClassification(Account $account): bool
    {
        if (! AccountTypeHelper::isAllowanceContraAssetName((string) $account->name)) {
            return false;
        }

        if ($account->type === AccountType::ContraAsset) {
            return false;
        }

        if ($account->category !== AccountCategory::Asset && $account->type->getCategory() !== AccountCategory::Asset) {
            // Jangan ubah expense/beban atau non-asset.
            return false;
        }

        $subtype = $this->resolveContraAssetAllowanceSubtype((int) $account->company_id);

        // AccountObserver::updating menimpa type/category dari relasi subtype.
        // Associate + unsetRelation mencegah subtype lama yang ter-cache.
        $account->unsetRelation('subtype');
        $account->subtype()->associate($subtype);
        $account->save();
        $account->refresh();

        return $account->type === AccountType::ContraAsset;
    }

    /**
     * Balik debit↔credit pada transaksi opening_balance_import yang salah memposting debit
     * ke akun penyisihan (harusnya credit). Idempotent: hanya transaksi yang masih debit di akun.
     *
     * @return int jumlah transaksi yang di-flip
     */
    public function flipOpeningBalanceImportDebits(Account $account): int
    {
        $companyId = (int) $account->company_id;
        $accountId = (int) $account->id;

        $transactionIds = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('account_id', $accountId)
            ->where('type', JournalEntryType::Debit)
            ->whereHas('transaction', function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->where('meta->addon', 'opening_balance_import');
            })
            ->pluck('transaction_id')
            ->unique()
            ->values();

        if ($transactionIds->isEmpty()) {
            return 0;
        }

        $flipped = 0;

        foreach ($transactionIds as $transactionId) {
            $entries = JournalEntry::query()
                ->where('company_id', $companyId)
                ->where('transaction_id', $transactionId)
                ->get();

            foreach ($entries as $entry) {
                $entry->update([
                    'type' => $entry->type === JournalEntryType::Debit
                        ? JournalEntryType::Credit
                        : JournalEntryType::Debit,
                ]);
            }

            $tx = Transaction::query()->find($transactionId);
            if ($tx) {
                $meta = $tx->meta ?? [];
                $meta['fixed_allowance_contra_asset'] = true;
                $meta['fixed_allowance_contra_asset_at'] = now()->toIso8601String();
                $tx->update(['meta' => $meta]);
            }

            $flipped++;
        }

        return $flipped;
    }

    public function resolveContraAssetAllowanceSubtype(int $companyId): AccountSubtype
    {
        $subtypeName = 'Allowance / Penyisihan';

        $subtype = AccountSubtype::query()
            ->where('company_id', $companyId)
            ->where('type', AccountType::ContraAsset)
            ->where('name', $subtypeName)
            ->first();

        if ($subtype) {
            return $subtype;
        }

        $subtype = AccountSubtype::query()
            ->where('company_id', $companyId)
            ->where('type', AccountType::ContraAsset)
            ->first();

        if ($subtype) {
            return $subtype;
        }

        return AccountSubtype::create([
            'company_id' => $companyId,
            'name' => $subtypeName,
            'category' => AccountCategory::Asset,
            'type' => AccountType::ContraAsset->value,
            'inverse_cash_flow' => AccountTypeHelper::isInverseCashFlow('Account Receivable'),
        ]);
    }

    private function resolveSubtype(int $companyId, string $accountTypeLabel, ?string $accountName = null): AccountSubtype
    {
        $accountType = AccountTypeHelper::map($accountTypeLabel, $accountName);
        if ($accountType === null) {
            throw new InvalidArgumentException("Unknown account type: {$accountTypeLabel}");
        }

        if (
            $accountType === AccountType::ContraAsset
            && AccountTypeHelper::isAllowanceContraAssetName((string) $accountName)
        ) {
            return $this->resolveContraAssetAllowanceSubtype($companyId);
        }

        $subtypeName = $accountTypeLabel;

        $subtype = AccountSubtype::query()
            ->where('company_id', $companyId)
            ->where('type', $accountType->value)
            ->where('name', $subtypeName)
            ->first();

        if ($subtype) {
            return $subtype;
        }

        // Fallback: any subtype with the resolved enum type.
        $subtype = AccountSubtype::query()
            ->where('company_id', $companyId)
            ->where('type', $accountType->value)
            ->first();

        if ($subtype) {
            return $subtype;
        }

        return AccountSubtype::create([
            'company_id' => $companyId,
            'name' => $subtypeName,
            'category' => $accountType->getCategory(),
            'type' => $accountType->value,
            'inverse_cash_flow' => AccountTypeHelper::isInverseCashFlow($accountTypeLabel),
        ]);
    }

    private function resolveParentId(int $companyId, string $code, ?string $parentCodeHint): ?int
    {
        $candidates = array_filter([
            $parentCodeHint,
            explode('.', $code)[0] ?? null,
            preg_match('/^(\d{3,4})/', $code, $m) ? $m[1] : null,
        ]);

        foreach ($candidates as $parentCode) {
            if ($parentCode === null || $parentCode === '' || $parentCode === $code) {
                continue;
            }

            $parent = Account::query()
                ->where('company_id', $companyId)
                ->where('code', $parentCode)
                ->first();

            if ($parent) {
                return $parent->id;
            }
        }

        return null;
    }
}
