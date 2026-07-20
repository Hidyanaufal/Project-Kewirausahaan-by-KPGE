<?php

namespace App\Services;

use App\DTO\AccountParentResolutionDTO;
use App\Models\Accounting\Account;

class AccountParentResolutionService
{
    public const FUZZY_THRESHOLD = 85.0;

    /**
     * @return array{account: ?Account, similarity: float, ambiguous: bool}
     */
    public function matchParentByName(int $companyId, string $excelParentName): array
    {
        $accounts = Account::query()
            ->where('company_id', $companyId)
            ->get(['id', 'code', 'name', 'parent_id']);

        $normalized = $this->normalizeName($excelParentName);
        $exact = $accounts->filter(
            fn (Account $account) => $this->normalizeName($account->name) === $normalized
        );

        if ($exact->count() === 1) {
            return ['account' => $exact->first(), 'similarity' => 100.0, 'ambiguous' => false];
        }

        if ($exact->count() > 1) {
            return ['account' => null, 'similarity' => 100.0, 'ambiguous' => true];
        }

        $best = null;
        $bestPercent = 0.0;
        $ties = 0;

        foreach ($accounts as $account) {
            similar_text($normalized, $this->normalizeName($account->name), $percent);
            if ($percent < self::FUZZY_THRESHOLD) {
                continue;
            }
            if ($percent > $bestPercent) {
                $best = $account;
                $bestPercent = $percent;
                $ties = 1;
            } elseif (abs($percent - $bestPercent) < 0.01) {
                $ties++;
            }
        }

        if ($best !== null && $ties === 1) {
            return ['account' => $best, 'similarity' => round($bestPercent, 2), 'ambiguous' => false];
        }

        return ['account' => null, 'similarity' => round($bestPercent, 2), 'ambiguous' => $ties > 1];
    }

    public function findNextChildCode(int $companyId, Account $parentAccount): string
    {
        $parentCode = (string) $parentAccount->code;
        $children = Account::query()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($parentAccount, $parentCode) {
                $query->where('parent_id', $parentAccount->id)
                    ->orWhere('code', 'like', $parentCode . '.%')
                    ->orWhere('code', 'like', $parentCode . '-%');
            })
            ->pluck('code');

        $maxSuffix = 0;
        $pad = 2;
        $separator = '.';

        foreach ($children as $childCode) {
            if (! is_string($childCode) || ! str_starts_with($childCode, $parentCode)) {
                continue;
            }

            $rest = substr($childCode, strlen($parentCode));
            if ($rest === '' || ($rest[0] !== '.' && $rest[0] !== '-')) {
                continue;
            }

            $separator = $rest[0];
            $suffix = substr($rest, 1);
            if (! ctype_digit($suffix)) {
                continue;
            }

            $pad = max($pad, strlen($suffix));
            $maxSuffix = max($maxSuffix, (int) $suffix);
        }

        return $parentCode . $separator . str_pad((string) ($maxSuffix + 1), $pad, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $excelRow
     */
    public function resolveForNewRow(int $companyId, array $excelRow): AccountParentResolutionDTO
    {
        $excelCode = ($excelRow['is_leaf'] ?? false)
            ? ($excelRow['sub_account_no'] ?? null)
            : ($excelRow['account_no'] ?? null);

        $excelParentName = $excelRow['excel_parent_name'] ?? null;
        $excelParentAccountNo = $excelRow['excel_parent_account_no'] ?? null;
        $excelParentAccountType = $excelRow['excel_parent_account_type'] ?? null;
        $excelParentRowNumber = isset($excelRow['excel_parent_row_number'])
            ? (int) $excelRow['excel_parent_row_number']
            : null;

        if ($excelParentName === null || $excelParentName === '') {
            return new AccountParentResolutionDTO(
                parentStatus: 'missing',
                parentAction: 'none',
                parentAccountId: null,
                parentSystemCode: null,
                parentSystemName: null,
                excelParentAccountNo: null,
                excelParentName: null,
                excelParentAccountType: null,
                excelParentRowNumber: null,
                suggestedCode: (string) ($excelCode ?? ''),
                codeSource: 'excel_fallback',
                excelCode: $excelCode,
            );
        }

        $match = $this->matchParentByName($companyId, $excelParentName);
        /** @var Account|null $parent */
        $parent = $match['account'];

        if ($parent !== null) {
            return new AccountParentResolutionDTO(
                parentStatus: 'found_existing',
                parentAction: 'use_existing',
                parentAccountId: $parent->id,
                parentSystemCode: (string) $parent->code,
                parentSystemName: (string) $parent->name,
                excelParentAccountNo: $excelParentAccountNo,
                excelParentName: $excelParentName,
                excelParentAccountType: $excelParentAccountType,
                excelParentRowNumber: $excelParentRowNumber,
                suggestedCode: $this->findNextChildCode($companyId, $parent),
                codeSource: 'system_next',
                excelCode: $excelCode,
            );
        }

        if ($excelParentAccountNo !== null && $excelParentAccountNo !== '') {
            $parentCode = $excelParentAccountNo;

            return new AccountParentResolutionDTO(
                parentStatus: 'needs_create',
                parentAction: 'create_parent',
                parentAccountId: null,
                parentSystemCode: null,
                parentSystemName: null,
                excelParentAccountNo: $excelParentAccountNo,
                excelParentName: $excelParentName,
                excelParentAccountType: $excelParentAccountType,
                excelParentRowNumber: $excelParentRowNumber,
                suggestedCode: $parentCode . '.01',
                codeSource: 'system_next',
                excelCode: $excelCode,
            );
        }

        return new AccountParentResolutionDTO(
            parentStatus: 'missing',
            parentAction: 'none',
            parentAccountId: null,
            parentSystemCode: null,
            parentSystemName: null,
            excelParentAccountNo: null,
            excelParentName: $excelParentName,
            excelParentAccountType: $excelParentAccountType,
            excelParentRowNumber: $excelParentRowNumber,
            suggestedCode: (string) ($excelCode ?? ''),
            codeSource: 'excel_fallback',
            excelCode: $excelCode,
        );
    }

    public function normalizeName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = str_replace(['.', ',', '(', ')', '-', '/'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
