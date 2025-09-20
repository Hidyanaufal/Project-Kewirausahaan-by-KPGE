<?php

namespace App\Imports;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Enums\Accounting\AccountType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ChartOfAccountImport implements ToModel, WithHeadingRow, WithChunkReading, ShouldQueue
{
    protected int $companyId;
    protected bool $reset;

    public function __construct(int $companyId, bool $reset = false)
    {
        $this->companyId = $companyId;

        if ($reset) {
            logger("COA Import - Reset enabled, deleting all accounts for company_id={$companyId}");
            Account::where('company_id', $companyId)->delete();
            logger("COA Import - All accounts deleted for company_id={$companyId}");
        } else {
            logger("COA Import - Reset disabled, existing accounts will be preserved for company_id={$companyId}");
        }
    }

    public function chunkSize(): int
    {
        return 200;
    }

    public function model(array $row)
    {
        Log::info('COA Import - Start processing row', $row);

        if (empty($row['code']) || empty($row['name'])) {
            Log::warning('COA Import - Skipped row (missing code/name)', $row);
            return null;
        }

        if (Account::where('company_id', $this->companyId)
            ->where('code', $row['code'])
            ->exists()
        ) {
            Log::info("COA Import - Skipped, account already exists: {$row['code']}");
            return null;
        }

        $typeValue = null;
        if (!empty($row['type'])) {
            $normalized = Str::snake(Str::lower(trim($row['type'])));
            Log::info("COA Import - Normalized type: {$row['type']} -> {$normalized}");

            try {
                $typeValue = AccountType::from($normalized);
            } catch (\ValueError $e) {
                Log::error("COA Import - Invalid type '{$row['type']}' for code {$row['code']}");
                throw ValidationException::withMessages([
                    'type' => "Invalid account type `{$row['type']}` for code {$row['code']}",
                ]);
            }
        } else {
            $typeValue = AccountType::CurrentAsset;
            Log::info("COA Import - Defaulting type to CurrentAsset for code {$row['code']}");
        }

        $categoryValue = $typeValue->getCategory();
        Log::info("COA Import - Category for {$row['code']} = {$categoryValue->value}");

        $subtypeId = null;
        if (!empty($row['subtype'])) {
            Log::info("COA Import - Looking for subtype '{$row['subtype']}'");
            $subtype = AccountSubtype::firstOrCreate(
                [
                    'company_id' => $this->companyId,
                    'name'       => $row['subtype'],
                ],
                [
                    'category'   => $categoryValue,
                    'type'       => $typeValue,
                    'description' => $row['subtype'],
                ]
            );
            $subtypeId = $subtype->id;
            Log::info("COA Import - Subtype assigned with ID {$subtypeId}");
        }

        $account = new Account([
            'company_id'    => $this->companyId,
            'subtype_id'    => $subtypeId,
            'parent_id'     => $row['parent_id'] ?? null,
            'category'      => $categoryValue,
            'type'          => $typeValue,
            'code'          => $row['code'],
            'name'          => $row['name'],
            'currency_code' => $row['currency_code'] ?? 'IDR',
            'description'   => $row['description'] ?? null,
            'archived'      => filter_var($row['archived'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'default'       => filter_var($row['default'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'created_by'    => auth()->id() ?? null,
        ]);

        Log::info("COA Import - Account ready to insert", $account->toArray());

        return $account;
    }
}
