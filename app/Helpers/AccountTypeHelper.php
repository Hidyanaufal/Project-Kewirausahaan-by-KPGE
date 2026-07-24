<?php

namespace App\Helpers;

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountType;

class AccountTypeHelper
{
    const database = [
        'Account Payable'           => AccountType::CurrentLiability,
        'Account Receivable'        => AccountType::CurrentAsset,
        'Accumulated Depreciation'  => AccountType::ContraAsset,
        'Cash/Bank'                 => AccountType::CurrentAsset,
        'Cost of Goods Sold'        => AccountType::OperatingExpense,
        'Equity'                    => AccountType::Equity,
        'Expense'                   => AccountType::OperatingExpense,
        'Fixed Asset'               => AccountType::NonCurrentAsset,
        'Inventory'                 => AccountType::CurrentAsset,
        'Other Current Asset'       => AccountType::CurrentAsset,
        'Other Current Liability'   => AccountType::CurrentLiability,
        'Other Expense'             => AccountType::NonOperatingExpense,
        'Other Income'              => AccountType::NonOperatingRevenue,
        'Revenue'                   => AccountType::OperatingRevenue,
    ];

    const bankAccount = [
        'Cash/Bank'
    ];

    const inverseCashFlow = [
        'Account Payable',
        'Equity',
        'Other Current Liability',
        'Revenue',
        'Other Income',
    ];

    public static function map(string $key, ?string $accountName = null): ?AccountType
    {
        $base = self::database[$key] ?? null;
        if ($base === null) {
            return null;
        }

        // Penyisihan / allowance di Excel sering berlabel AR/asset, tapi seharusnya Contra Asset (kredit).
        if (
            $accountName !== null
            && self::isAllowanceContraAssetName($accountName)
            && $base->getCategory() === AccountCategory::Asset
            && ! $base->isContra()
        ) {
            return AccountType::ContraAsset;
        }

        return $base;
    }

    /**
     * Nama akun penyisihan piutang / allowance (bukan beban penyisihan).
     */
    public static function isAllowanceContraAssetName(string $accountName): bool
    {
        $name = mb_strtolower(trim($accountName));
        if ($name === '') {
            return false;
        }

        // Beban/expense penyisihan tetap expense — jangan override ke contra.
        if (str_contains($name, 'beban') || str_contains($name, 'biaya')) {
            return false;
        }

        return str_contains($name, 'penyisihan')
            || str_contains($name, 'allowance')
            || (str_contains($name, 'provisi') && str_contains($name, 'piutang'));
    }

    public static function isInverseCashFlow(string $key): bool
    {
        return ! in_array($key, self::inverseCashFlow, true);
    }

    public static function isBankAccount(string $key): bool
    {
        return in_array($key, self::bankAccount, true);
    }
}
