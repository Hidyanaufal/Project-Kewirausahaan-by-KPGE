<?php

namespace App\Imports;

use App\Casts\RateCast;
use App\Enums\Accounting\AdjustmentCategory;
use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\AdjustmentStatus;
use App\Enums\Banking\BankAccountType;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Helpers\AccountTypeHelper;
use App\Helpers\AuthHelper;
use App\Models\Accounting\Adjustment;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
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
    protected bool $importBalance;

    public function __construct(
        int $companyId,
        bool $reset = false,
        bool $importBalance = true
    ) {
        $this->companyId      = $companyId;
        $this->reset          = $reset;
        $this->importBalance  = $importBalance;

        if ($reset) {
            Log::warning("COA Import - Reset enabled, deleting all accounts for company_id={$companyId}");
            Account::where('company_id', $companyId)->delete();
            AccountSubtype::where('company_id', $companyId)->delete();
            BankAccount::where('company_id', $companyId)->delete();
            Adjustment::where('company_id', $companyId)->delete();
            Log::warning("COA Import - All accounts deleted for company_id={$companyId}");
        } else {
            Log::info("COA Import - Reset disabled, preserving existing accounts for company_id={$companyId}");
        }
    }

    public function chunkSize(): int
    {
        return 200;
    }

    protected function floatParser($value): float
    {
        $value = str_replace('.', '', $value);  // hapus ribuan
        $value = str_replace(',', '.', $value); // ubah desimal
        return (float) $value;
    }

    public function model(array $row)
    {
        Log::info("COA Import - Start processing row", $row);

        // Validasi account_name
        if (empty($row['account_name'])) {
            Log::warning("COA Import - Skipped row (missing account_name)", $row);
            return null;
        }

        // Tentukan parent/child
        $isParent = !empty($row['account_no']);
        $isChild  = !$isParent;
        $row['code'] = $isParent ? $row['account_no'] : ($row['sub_account_no'] ?? null);

        if (!$row['code']) {
            Log::warning("COA Import - Skipped row (missing code)", $row);
            return null;
        }

        // Cek duplikat
        if (Account::where('company_id', $this->companyId)->where('code', $row['code'])->exists()) {
            Log::info("COA Import - Skipped, account already exists: {$row['code']}");
            return null;
        }

        // Handle Subtype
        $accountSub = null;
        if (!empty($row['account_type'])) {
            $accountSub = AccountSubtype::where([
                'name'       => $row['account_type'],
                'company_id' => $this->companyId
            ])->first();

            if (!$accountSub) {
                Log::info("COA Import - Creating new AccountSubtype: {$row['account_type']}");
                $accountType = $row['account_type'];
                $accountSub  = AccountSubtype::create([
                    'company_id'        => $this->companyId,
                    'name'              => $row['account_type'],
                    'category'          => AccountTypeHelper::map($accountType)->getCategory(),
                    'type'              => AccountTypeHelper::map($accountType)->value,
                    'inverse_cash_flow' => AccountTypeHelper::isInverseCashFlow($accountType),
                ]);
            } else {
                Log::info("COA Import - Using existing AccountSubtype: {$accountSub->name}");
            }
        }

        // Cari Parent
        $parentId = null;
        if ($isChild) {
            $childParts = explode('.', $row['code']); // contoh: 1100.02
            $estParentCode = $childParts[0] ?? null; // 1100

            if ($estParentCode) {
                $accountParent = Account::where('company_id', $this->companyId)
                    ->where('code', $estParentCode)
                    ->first();

                if ($accountParent) {
                    $parentId = $accountParent->id;
                    Log::info("COA Import - Child {$row['code']} linked to Parent {$accountParent->code}");
                } else {
                    Log::warning("COA Import - No parent found for child {$row['code']}");
                }
            }

            // Case khusus KPG jika lebih dari 9500
            if (intval($row['code']) > 9500 and intval($row['code']) < 9600) {
                $accountParent = Account::where([
                    'company_id' => $this->companyId,
                    'code' => '9500'
                ])->first();
                $parentId = $accountParent->id;
            }
        }

        // Buat Account
        $account = Account::create([
            'company_id'    => $this->companyId,
            'subtype_id'    => $accountSub?->id,
            'parent_id'     => $parentId,
            'category'      => $accountSub?->category,
            'type'          => $accountSub?->type,
            'code'          => $row['code'],
            'name'          => $row['account_name'],
            'currency_code' => $row['currency_code'] ?? 'IDR',
            'description'   => $row['description'] ?? null,
            'archived'      => filter_var($row['archived'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'default'       => filter_var($row['default'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ]);

        Log::info("COA Import - Account created", $account->toArray());

        $row['account_id'] = $account->id;

        // Jika child dan BankAccount
        if ($isChild && AccountTypeHelper::isBankAccount($accountSub->name)) {
            BankAccount::create([
                'company_id' => $this->companyId,
                'account_id' => $account->id,
                'type'       => BankAccountType::Depository->value,
                'number'     => $row['account_number'] ?? null,
                'enabled'    => 0,
            ]);
            Log::info("COA Import - BankAccount created for account {$account->code}");
        }

        if ($this->importBalance) {
            // Jika child → buat transaksi + jurnal
            if ($isChild) {
                $balance = $this->floatParser($row['balance'] ?? 0);
                if ($balance != 0) {
                    Log::debug("COA Import - Parsed Balance", [
                        'code' => $row['code'],
                        'balance' => $balance
                    ]);

                    $transaction = Transaction::create([
                        'company_id'      => $this->companyId,
                        'account_id'      => $row['account_id'],
                        'bank_account_id' => $row['bank_account_id'] ?? null,
                        'type'            => $balance < 0 ? 'withdrawal' : 'deposit',
                        'description'     => 'Auto Import',
                        'posted_at'       => now(),
                        'amount'          => abs($balance * 100), // disimpan dalam cent
                        'reviewed'        => true
                    ]);

                    Log::info("COA Import - Transaction created", $transaction->toArray());

                    // Buat JournalEntry (double entry simplified)
                    $isDebit = $balance < 0;
                    $entryType = $isDebit ? 'debit' : 'credit';

                    if ($accountSub->inverse_cash_flow) {
                        if ($entryType == 'debit') {
                            $entryType = 'credit';
                        } else {
                            $entryType = 'debit';
                        }
                    }

                    Log::info("COA Import - JournalEntry Process for Account ID: " . $row['account_id'], [
                        'entryType'         => $entryType,
                        'code'              => $row['code'],
                        'balance'           => $balance,
                        'account_sub_name'  => $accountSub->name,
                        'inverse_cash_flow' => $accountSub->inverse_cash_flow
                    ]);

                    // cek duplikat journal entry
                    if (JournalEntry::where('company_id', $this->companyId)->where('account_id', $account->id)->exists()) {
                        Log::info("COA Import - Journal Entry Skipped, account already exists: {$row['code']}");
                        return null;
                    }

                    $journal = JournalEntry::create([
                        'transaction_id' => $transaction->id,
                        'company_id'     => $this->companyId,
                        'account_id'     => $row['account_id'],
                        'type'           => $entryType,
                        'amount'         => abs($balance * 100),
                        'description'    => 'Auto Import',
                    ]);
                    Log::info("COA Import - JournalEntry Created for Account ID: " . $row['account_id'], [
                        'account_code'  => $row['code'],
                        'journal'       => $journal,
                    ]);
                } else {
                    Log::debug("COA Import - Skipped Balance 0", ['code' => $row['code']]);
                }
            }
        }

        // Insert otomatis Adjustment
        if (in_array($account->name, ['PPn Masukan', 'PPn Keluaran'])) {
            $type = $account->name == 'PPn Masukan' ? 'purchase' : 'sales';
            Adjustment::create([
                'company_id' => $this->companyId,
                'name' => 'PPN 11',
                'status' => AdjustmentStatus::Active,
                'category' => AdjustmentCategory::Tax,
                'computation' => AdjustmentComputation::Percentage,
                'rate' => 110000,
                'type' => $type,
                'account_id' => $account->id
            ]);
            Log::info("COA Import - Adjusment created for account {$account->code}");
        }


        Log::info("COA Import - Finished processing row {$row['code']}");

        return $account;
    }
}
