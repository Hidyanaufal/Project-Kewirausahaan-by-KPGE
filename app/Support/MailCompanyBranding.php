<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Mail\Mailables\Address;

class MailCompanyBranding
{
    public static function resolveCompanyName(?int $companyId): string
    {
        $name = null;

        if ($companyId) {
            $name = Company::query()->whereKey($companyId)->value('name');
        }

        $name = $name ?: (string) config('mail.from.name');
        $name = $name ?: (string) config('app.name');

        return trim($name) !== '' ? trim($name) : 'Company';
    }

    public static function prefixSubject(string $subject, string $companyName): string
    {
        $subject = trim($subject);
        $companyName = trim($companyName);

        if ($subject === '') {
            return '[' . $companyName . ']';
        }

        if ($companyName === '') {
            return $subject;
        }

        $prefix = '[' . $companyName . '] ';
        if (str_starts_with($subject, $prefix)) {
            return $subject;
        }

        return $prefix . $subject;
    }

    public static function companyFromAddress(string $companyName): Address
    {
        return new Address((string) config('mail.from.address'), $companyName);
    }
}

