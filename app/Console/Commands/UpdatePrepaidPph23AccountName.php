<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Accounting\Account;
use App\Models\Accounting\Bill;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;

class UpdatePrepaidPph23AccountName extends Command
{
    protected $signature = 'account:update-pph23-name {--company_id= : ID company yang akan di-update}';

    protected $description = 'Mengganti account_id di journal entries Bills lama dari Prepaid PPh 23 ke Hutang PPh 23';

    public function handle(): void
    {
        $companyId = $this->option('company_id');

        if (! $companyId) {
            $companyCount = Company::query()->count();
            if ($companyCount === 1) {
                $companyId = Company::query()->value('id');
                $this->info("company_id tidak diberikan. Otomatis memakai satu-satunya company_id: {$companyId}");
            } else {
                $this->error('company_id wajib diisi saat menjalankan command ini (karena di console tidak ada CurrentCompanyScope).');
                $this->line('Contoh: php artisan account:update-pph23-name --company_id=1');
                return;
            }
        }

        $companyId = (int) $companyId;

        // Cari akun lama yang salah (Prepaid PPh 23)
        $oldAccount = Account::query()
            ->where('company_id', $companyId)
            ->where('name', 'Prepaid PPh 23')
            ->first();

        // Cari akun baru yang benar (Hutang PPh 23)
        $newAccount = Account::query()
            ->where('company_id', $companyId)
            ->where('name', 'Hutang PPh 23')
            ->first();

        if (! $oldAccount || ! $newAccount) {
            $this->error('Salah satu akun tidak ditemukan di company ini. Pastikan kedua akun ada di database pada company_id yang sama.');
            return;
        }

        // Cari journal entries Bills yang masih pakai akun lama
        $count = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('account_id', $oldAccount->id)
            ->whereHas('transaction', function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->where('transactionable_type', Bill::class);
            })
            ->count();

        if ($count === 0) {
            $this->info('Tidak ada journal entries Bills yang perlu diubah.');
            return;
        }

        $this->info("Ditemukan {$count} journal entries (Bills) yang akan diubah di company_id={$companyId}...");

        // Ganti account_id ke akun yang benar
        $affected = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('account_id', $oldAccount->id)
            ->whereHas('transaction', function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->where('transactionable_type', Bill::class);
            })
            ->update(['account_id' => $newAccount->id]);

        $remaining = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('account_id', $oldAccount->id)
            ->whereHas('transaction', function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->where('transactionable_type', Bill::class);
            })
            ->count();

        $this->info("Update selesai. Rows ter-update: {$affected}. Sisa yang masih pakai akun lama: {$remaining}.");
    }
}