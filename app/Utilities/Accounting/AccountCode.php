<?php

namespace App\Utilities\Accounting;

use App\Enums\Accounting\AccountType;
use App\Models\Accounting\AccountSubtype;
use RuntimeException;

class AccountCode
{
    public static function isValidCode($code, AccountType $type): bool
    {
        $range = self::getRangeForType($type);

        $mainAccountPart = explode('-', $code)[0];

        $numericValue = (int) $mainAccountPart;

        return $numericValue >= $range[0] && $numericValue <= $range[1];
    }

    public static function getMessage(AccountType $type): string
    {
        $range = self::getRangeForType($type);

        return "The account code must range from {$range[0]} to {$range[1]} for a {$type->getLabel()}.";
    }

    public static function getRangeForType(AccountType $type): array
    {
        return match ($type) {
            default => [0, 9999999999999]
        };
    }

    public static function generate(AccountSubtype $accountSubtype): string
    {
        $subtypeName = $accountSubtype->name;
        $typeEnum = $accountSubtype->type;
        $typeValue = $typeEnum->value;

        $baseCode = config("chart-of-accounts.default.{$typeValue}.{$subtypeName}.base_code");
        $range = self::getRangeForType($typeEnum);

        $lastAccount = $accountSubtype->accounts()
            ->whereNotNull('code')
            ->orderBy('code', 'desc')
            ->first();

        $nextNumericValue = $lastAccount ? (int) explode('-', $lastAccount->code)[0] + 1 : (int) $baseCode;

        if ($nextNumericValue > $range[1]) {
            throw new RuntimeException("The account code range for a {$typeEnum->getLabel()} has been exceeded.");
        }

        while ($accountSubtype->accounts()->where('code', '=', (string) $nextNumericValue)->exists()) {
            $nextNumericValue++;

            if ($nextNumericValue > $range[1]) {
                throw new RuntimeException("The account code range for a {$typeEnum->getLabel()} has been exceeded.");
            }
        }

        return (string) $nextNumericValue;
    }
}
