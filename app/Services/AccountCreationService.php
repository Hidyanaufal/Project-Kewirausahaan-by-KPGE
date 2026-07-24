<?php

namespace App\Services;

use App\Enums\Accounting\AccountType;
use App\Helpers\AccountTypeHelper;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
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

    private function resolveSubtype(int $companyId, string $accountTypeLabel, ?string $accountName = null): AccountSubtype
    {
        $accountType = AccountTypeHelper::map($accountTypeLabel, $accountName);
        if ($accountType === null) {
            throw new InvalidArgumentException("Unknown account type: {$accountTypeLabel}");
        }

        $subtypeName = $accountType === AccountType::ContraAsset
            && AccountTypeHelper::isAllowanceContraAssetName((string) $accountName)
            ? 'Allowance / Penyisihan'
            : $accountTypeLabel;

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
