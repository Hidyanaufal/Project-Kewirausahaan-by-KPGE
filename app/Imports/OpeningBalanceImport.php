<?php

namespace App\Imports;

use App\Enums\Accounting\JournalEntryType;
use App\Models\Accounting\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
use App\Models\Company;
use App\Services\AccountService;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class OpeningBalanceImport implements ToModel, WithHeadingRow, WithChunkReading, ShouldQueue
{
    public function __construct(
        protected int $companyId,
        protected int $year,
    ) {}

    public function chunkSize(): int
    {
        return 200;
    }

    public function model(array $row)
    {
        $code = $this->extractAccountCode($row);
        if ($code === null) {
            return null;
        }

        $balanceRaw = $row['balance'] ?? $row['saldo'] ?? $row['saldo_awal'] ?? null;
        if ($balanceRaw === null || $balanceRaw === '') {
            return null;
        }

        $account = Account::query()
            ->where('company_id', $this->companyId)
            ->where('code', $code)
            ->first();

        if (! $account) {
            Log::warning('OpeningBalanceImport - account not found', [
                'company_id' => $this->companyId,
                'code' => $code,
                'row' => $row,
            ]);
            return null;
        }

        $company = Company::query()->find($this->companyId);
        if (! $company) {
            return null;
        }

        $fyStart = $this->computeFiscalYearStartForYear($company, $this->year);
        $postedAt = $fyStart->copy()->subSecond()->toDateTimeString();
        $startDateTime = $fyStart->toDateTimeString();

        /** @var AccountService $accountService */
        $accountService = app(AccountService::class);

        $currentStarting = $accountService->getStartingBalance(
            $account,
            $startDateTime,
            override: true,
            projectId: null,
            companyId: $this->companyId,
        );
        $currentMinor = $currentStarting?->getAmount() ?? 0;

        $desiredMinor = $this->parseBalanceToMinor($balanceRaw, CurrencyAccessor::getDefaultCurrency());
        $diff = $desiredMinor - $currentMinor;

        if ($diff === 0) {
            return null;
        }

        $openingEquity = Account::getOpeningBalanceAccount($this->companyId);

        [$accountEntryType, $equityEntryType] = $this->determineEntryTypes($account, $diff);
        $amount = abs($diff);

        DB::transaction(function () use ($account, $openingEquity, $postedAt, $amount, $accountEntryType, $equityEntryType) {
            $transaction = Transaction::create([
                'company_id' => $this->companyId,
                'bank_account_id' => null,
                'type' => 'journal',
                'description' => "Saldo Awal FY-{$this->year} (Import Adjust)",
                'posted_at' => $postedAt,
                'amount' => $amount,
                'reviewed' => false,
                'meta' => [
                    'addon' => 'opening_balance_import',
                    'year' => $this->year,
                    'account_code' => $account->code,
                ],
            ]);

            JournalEntry::create([
                'company_id' => $this->companyId,
                'transaction_id' => $transaction->id,
                'account_id' => $account->id,
                'type' => $accountEntryType,
                'amount' => $amount,
                'description' => "Saldo Awal FY-{$this->year} - {$account->code}",
            ]);

            JournalEntry::create([
                'company_id' => $this->companyId,
                'transaction_id' => $transaction->id,
                'account_id' => $openingEquity->id,
                'type' => $equityEntryType,
                'amount' => $amount,
                'description' => "Saldo Awal FY-{$this->year} (Counter)",
            ]);
        });

        return $account;
    }

    private function extractAccountCode(array $row): ?string
    {
        $candidates = [
            $row['code'] ?? null,
            $row['account_code'] ?? null,
            $row['account_no'] ?? null,
            $row['sub_account_no'] ?? null,
            $row['akun'] ?? null,
        ];

        foreach ($candidates as $value) {
            if ($value === null) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function computeFiscalYearStartForYear(Company $company, int $year): Carbon
    {
        $currentYear = company_today()->year;
        $baseStart = Carbon::parse($company->locale->fiscalYearStartDate())->startOfDay();

        return $baseStart->subYears($currentYear - $year)->startOfDay();
    }

    private function parseBalanceToMinor(string|float|int $raw, string $currency): int
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            $raw = str_replace([' ', "\u{00A0}"], '', $raw);
            $raw = str_replace('.', '', $raw);   // ribuan (ID style)
            $raw = str_replace(',', '.', $raw);  // desimal (ID style)
        }

        return CurrencyConverter::convertToCents((float) $raw, $currency);
    }

    /**
     * @return array{0: JournalEntryType, 1: JournalEntryType}
     */
    private function determineEntryTypes(Account $account, int $diff): array
    {
        $normalDebit = $account->category->isNormalDebitBalance();

        if ($normalDebit) {
            $accountType = $diff > 0 ? JournalEntryType::Debit : JournalEntryType::Credit;
        } else {
            $accountType = $diff > 0 ? JournalEntryType::Credit : JournalEntryType::Debit;
        }

        $equityType = $accountType === JournalEntryType::Debit ? JournalEntryType::Credit : JournalEntryType::Debit;

        return [$accountType, $equityType];
    }
}

