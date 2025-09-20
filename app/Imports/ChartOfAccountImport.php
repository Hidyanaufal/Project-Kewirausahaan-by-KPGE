<?php

namespace App\Imports;

use App\Enums\Banking\BankAccountType;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Helpers\AccountTypeHelper;
use App\Models\Banking\BankAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
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
            AccountSubtype::where('company_id', $companyId)->delete();
            BankAccount::where('company_id', $companyId)->delete();
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
        /*
        {
            "account_no": "1600",
            "sub_account_no": null,
            "account_name": "Piutang Pihak Berelasi",
            "account_type": "Other Current Asset",
            "balance": "7.245.579.544,00",
        },
        {
            "account_no": null,
            "sub_account_no": "1500.01",
            "account_name": "PPn Masukan",
            "account_type": "Other Current Asset",
            "balance": "0,00",
        },
        {
            "account_no": null,
            "sub_account_no": "1600.01",
            "account_name": "Piutang PT. KPP",
            "account_type": "Other Current Asset",
            "balance": "4.664.887.221,00",
        },
        {
            "account_no": null,
            "sub_account_no": "1100.09",
            "account_name": "Penyisihan Piutang Tidak tertagih",
            "account_type": "Account Receivable",
            "balance": "-1.582.479.465,37",
        }
         */
        Log::info('COA Import - Start processing row', $row);

        if (empty($row['account_name'])) {
            Log::warning('COA Import - Skipped row (missing account_name)', $row);
            return null;
        }

        $isParent = false;
        if ($row['account_no']) {
            $isParent = true;
        }
        $isChild = !$isParent;

        if ($isParent) {
            $row['code'] = $row['account_no'];
        } else {
            $row['code'] = $row['sub_account_no'];
        }

        if (!$row['code']) {
            Log::warning('COA Import - Skipped row (missing code)', $row);
            return null;
        }

        if (Account::where('company_id', $this->companyId)
            ->where('code', $row['code'])
            ->exists()
        ) {
            Log::info("COA Import - Skipped, account already exists: {$row['code']}");
            return null;
        }

        if ($row['account_type']) {
            $accountSub = AccountSubtype::where([
                'name' => $row['account_type']
            ])->first();

            if (!$accountSub) {
                $accountType = $row['account_type'];
                $accountSub = AccountSubtype::create([
                    'company_id' => $this->companyId,
                    'name' => $row['account_type'],
                    'category' => AccountTypeHelper::map($accountType)->getCategory(),
                    'type' => AccountTypeHelper::map($accountType)->value,
                    'inverse_cash_flow' => AccountTypeHelper::isInverseCashFlow($accountType)
                ]);
            }
        }

        $parentId = null;
        if ($isChild) {
            $childCode = $row['code']; // '1100.02'
            $childCode = explode('.', $childCode); // ['1100', '02']
            $estParentCode = $childCode[0]; // '1100'
            $accountParent = Account::where([
                'company_id' => $this->companyId,
                'code' => $estParentCode
            ])->first();

            if ($accountParent) {
                $parentId = $accountParent->id;
                Log::info("COA Import - Parent Account detected");
                Log::info("COA Import - Account {$row['code']} is Child from {$accountParent->code}");
            }
        }

        $account = Account::create([
            'company_id'    => $this->companyId,
            'subtype_id'    => $accountSub->id,
            'parent_id'     => $parentId,
            'category'      => $accountSub->category,
            'type'          => $accountSub->type,
            'code'          => $row['code'],
            'name'          => $row['account_name'],
            'currency_code' => $row['currency_code'] ?? 'IDR',
            'description'   => $row['description'] ?? null,
            'archived'      => filter_var($row['archived'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'default'       => filter_var($row['default'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ]);

        if ($isChild) {
            if (AccountTypeHelper::isBankAccount($accountSub->name)) {
                BankAccount::create([
                    'company_id' => $this->companyId,
                    'account_id' => $account->id,
                    'type' => BankAccountType::Depository->value,
                    'enabled' => 0,
                ]);
            }
        }

        Log::info("COA Import - Account ready to insert", $account->toArray());

        return $account;
    }
}
