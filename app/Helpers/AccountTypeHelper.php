<?php

namespace App\Helpers;

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

    public static function map(string $key): ?AccountType
    {
        return self::database[$key] ?? null;
    }

    public static function isInverseCashFlow(string $key): bool
    {
        return in_array($key, self::inverseCashFlow, true);
    }

    public static function isBankAccount(string $key): bool
    {
        return in_array($key, self::bankAccount, true);
    }
}
