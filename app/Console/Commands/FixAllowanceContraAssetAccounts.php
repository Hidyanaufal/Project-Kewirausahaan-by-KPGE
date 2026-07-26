<?php

namespace App\Console\Commands;

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryType;
use App\Helpers\AccountTypeHelper;
use App\Models\Accounting\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Services\AccountCreationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixAllowanceContraAssetAccounts extends Command
{
    protected $signature = 'accounting:fix-allowance-contra-asset
                            {--company-id= : Batasi ke satu company ID}
                            {--all-companies : Jalankan untuk semua company}
                            {--dry-run : Preview tanpa mengubah data (default jika tanpa --apply)}
                            {--apply : Terapkan perbaikan tipe + flip jurnal saldo awal}
                            {--skip-flip : Hanya ubah tipe akun, jangan flip jurnal opening balance}';

    protected $description = 'Perbaiki akun penyisihan/allowance yang masih CurrentAsset menjadi ContraAsset dan balik sisi D/C jurnal import saldo awal';

    public function handle(AccountCreationService $accountCreation): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply || (bool) $this->option('dry-run');
        $skipFlip = (bool) $this->option('skip-flip');

        $companyIds = $this->resolveCompanyIds();
        if ($companyIds === []) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Mode dry-run: tidak ada perubahan yang disimpan. Tambah --apply untuk mengeksekusi.');
        }

        $totalAccounts = 0;
        $totalFlipped = 0;

        foreach ($companyIds as $companyId) {
            [$fixed, $flipped] = $this->processCompany(
                (int) $companyId,
                $accountCreation,
                $dryRun,
                $skipFlip,
            );
            $totalAccounts += $fixed;
            $totalFlipped += $flipped;
        }

        $this->newLine();
        $this->info("Selesai. Akun diperbaiki: {$totalAccounts}. Jurnal transaksi di-flip: {$totalFlipped}.");

        if (! $dryRun && $totalAccounts > 0) {
            $this->comment('Cek Trial Balance / Balance Sheet untuk akun penyisihan. Import ulang opsional jika saldo masih perlu disesuaikan.');
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveCompanyIds(): array
    {
        if ($this->option('all-companies')) {
            return Company::query()->orderBy('id')->pluck('id')->all();
        }

        $companyId = $this->option('company-id');
        if ($companyId) {
            return [(int) $companyId];
        }

        $count = Company::query()->count();
        if ($count === 1) {
            $id = (int) Company::query()->value('id');
            $this->info("company-id tidak diberikan. Memakai satu-satunya company_id: {$id}");

            return [$id];
        }

        $this->error('Wajib --company-id= atau --all-companies.');
        $this->line('Contoh: php artisan accounting:fix-allowance-contra-asset --company-id=1 --dry-run');
        $this->line('        php artisan accounting:fix-allowance-contra-asset --company-id=1 --apply');

        return [];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function processCompany(
        int $companyId,
        AccountCreationService $accountCreation,
        bool $dryRun,
        bool $skipFlip,
    ): array {
        $company = Company::query()->find($companyId);
        $label = $company?->name ?? "Company #{$companyId}";

        $this->newLine();
        $this->info("{$label} (ID {$companyId})");

        $candidates = Account::query()
            ->where('company_id', $companyId)
            ->where('category', AccountCategory::Asset)
            ->where('type', '!=', AccountType::ContraAsset)
            ->get()
            ->filter(fn (Account $account) => AccountTypeHelper::isAllowanceContraAssetName((string) $account->name))
            ->values();

        if ($candidates->isEmpty()) {
            $this->line('  Tidak ada akun penyisihan yang salah tipe.');

            return [0, 0];
        }

        $rows = [];
        foreach ($candidates as $account) {
            $debitOb = $this->countWrongOpeningDebits($companyId, (int) $account->id);
            $rows[] = [
                $account->id,
                $account->code,
                $account->name,
                $account->type->value,
                $debitOb,
            ];
        }

        $this->table(
            ['ID', 'Code', 'Name', 'Type saat ini', 'OB debit (akan di-flip)'],
            $rows,
        );

        if ($dryRun) {
            return [$candidates->count(), 0];
        }

        $fixed = 0;
        $flippedTx = 0;

        DB::transaction(function () use (
            $candidates,
            $accountCreation,
            $skipFlip,
            &$fixed,
            &$flippedTx,
        ) {
            foreach ($candidates as $account) {
                $fromType = $account->type->value;
                $changed = $accountCreation->ensureAllowanceContraAssetClassification($account);
                if ($changed) {
                    $fixed++;
                    $this->line("  ✓ [{$account->id}] {$account->code} {$account->name}: {$fromType} → contra_asset");
                }

                if ($skipFlip) {
                    continue;
                }

                $flipped = $accountCreation->flipOpeningBalanceImportDebits($account->fresh());
                $flippedTx += $flipped;
                if ($flipped > 0) {
                    $this->line("    ↳ flipped {$flipped} transaksi opening_balance_import");
                }
            }
        });

        return [$fixed, $flippedTx];
    }

    private function countWrongOpeningDebits(int $companyId, int $accountId): int
    {
        return JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('account_id', $accountId)
            ->where('type', JournalEntryType::Debit)
            ->whereHas('transaction', function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->where('meta->addon', 'opening_balance_import');
            })
            ->count();
    }
}
