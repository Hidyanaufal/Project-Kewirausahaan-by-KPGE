<?php

namespace App\Imports;

use App\Models\Accounting\Account;
use App\Models\Company;
use App\Services\OpeningBalanceNameImportService;
use App\Utilities\Currency\CurrencyAccessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class OpeningBalanceImport implements ShouldQueue, ToModel, WithChunkReading, WithHeadingRow
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

        if ($account->isParentAccount()) {
            Log::info('OpeningBalanceImport - skipped parent account balance to prevent double counting', [
                'company_id' => $this->companyId,
                'code' => $account->code,
            ]);

            return null;
        }

        /** @var OpeningBalanceNameImportService $service */
        $service = app(OpeningBalanceNameImportService::class);

        if ($service->isNominalPnLAccount($account)) {
            Log::info('OpeningBalanceImport - skipped nominal P&L account', [
                'company_id' => $this->companyId,
                'code' => $account->code,
                'name' => $account->name,
            ]);

            return null;
        }

        if (! Company::query()->whereKey($this->companyId)->exists()) {
            return null;
        }

        $desiredMinor = $service->parseBalanceToMinor($balanceRaw, CurrencyAccessor::getDefaultCurrency());
        $service->postOpeningBalanceAdjustment($this->companyId, $account, $this->year, $desiredMinor);

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
}
