<?php

namespace App\Console\Commands;

use App\Models\Accounting\Account;
use App\Models\Accounting\Bill;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class UpdatePrepaidPph23AccountName extends Command
{
    protected $signature = 'account:update-pph23-name
                            {--company_id= : ID company yang akan di-update}
                            {--all-companies : Jalankan untuk semua company}
                            {--dry-run : Preview tanpa mengubah data}';

    protected $description = 'Mengganti account_id di journal entries Bills lama dari Prepaid PPh 23 ke Hutang PPh 23';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $allCompanies = (bool) $this->option('all-companies');
        $companyId = $this->option('company_id');

        if ($allCompanies) {
            $companyIds = Company::query()->pluck('id')->all();
        } else {
            $resolved = $this->resolveCompanyId($companyId);

            if ($resolved === null) {
                return self::FAILURE;
            }

            $companyIds = [$resolved];
        }

        if ($dryRun) {
            $this->warn('Mode dry-run: tidak ada perubahan yang disimpan.');
        }

        $totalUpdated = 0;

        foreach ($companyIds as $id) {
            $updated = $this->migrateCompany((int) $id, $dryRun);
            $totalUpdated += $updated;
        }

        $this->info("Selesai. Total journal entries ter-update: {$totalUpdated}.");

        return self::SUCCESS;
    }

    protected function resolveCompanyId(mixed $companyId): ?int
    {
        if ($companyId) {
            return (int) $companyId;
        }

        $companyCount = Company::query()->count();

        if ($companyCount === 1) {
            $resolved = (int) Company::query()->value('id');
            $this->info("company_id tidak diberikan. Otomatis memakai satu-satunya company_id: {$resolved}");

            return $resolved;
        }

        $this->error('company_id wajib diisi saat menjalankan command ini (karena di console tidak ada CurrentCompanyScope).');
        $this->line('Contoh: php artisan account:update-pph23-name --company_id=1');
        $this->line('Atau: php artisan account:update-pph23-name --all-companies --dry-run');

        return null;
    }

    protected function migrateCompany(int $companyId, bool $dryRun): int
    {
        $this->newLine();
        $this->info("Company ID: {$companyId}");

        try {
            $oldAccount = Account::getAccountsPPh23($companyId);
            $newAccount = Account::getAccountsPPh23Payable($companyId);
        } catch (Throwable $e) {
            $this->error("  Lewati: gagal resolve akun via COA default — {$e->getMessage()}");

            return 0;
        }

        if ($oldAccount->id === $newAccount->id) {
            $this->line('  Prepaid dan Hutang PPh 23 mengarah ke akun yang sama. Tidak ada yang perlu diubah.');

            return 0;
        }

        $query = $this->billPaymentJournalQuery($companyId, $oldAccount->id);
        $count = (clone $query)->count();

        if ($count === 0) {
            $this->line('  Tidak ada journal entries Bills payment yang perlu diubah.');

            return 0;
        }

        $this->line("  Ditemukan {$count} journal entries (Prepaid → Hutang).");
        $this->line("  Dari: [{$oldAccount->id}] {$oldAccount->name}");
        $this->line("  Ke:   [{$newAccount->id}] {$newAccount->name}");

        $samples = (clone $query)
            ->with(['transaction'])
            ->limit(5)
            ->get();

        foreach ($samples as $entry) {
            $billId = $entry->transaction?->transactionable_id;
            $this->line("    Sample JE #{$entry->id} (transaction #{$entry->transaction_id}, bill #{$billId}) amount={$entry->amount}");
        }

        if ($dryRun) {
            return 0;
        }

        $affected = $query->update(['account_id' => $newAccount->id]);
        $remaining = (clone $this->billPaymentJournalQuery($companyId, $oldAccount->id))->count();

        $this->info("  Update selesai. Rows ter-update: {$affected}. Sisa prepaid: {$remaining}.");

        return $affected;
    }

    /**
     * @return Builder<JournalEntry>
     */
    protected function billPaymentJournalQuery(int $companyId, int $oldAccountId): Builder
    {
        return JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('account_id', $oldAccountId)
            ->whereHas('transaction', function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->where('transactionable_type', Bill::class)
                    ->where('is_payment', true);
            });
    }
}
