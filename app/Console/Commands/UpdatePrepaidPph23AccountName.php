<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Accounting\Account;
use App\Models\Accounting\Bill;
use App\Models\Accounting\JournalEntry;

class UpdatePrepaidPph23AccountName extends Command
{
    protected $signature = 'account:update-pph23-name';

    protected $description = 'Mengganti account_id di journal entries Bills lama dari Prepaid PPh 23 ke Hutang PPh 23';

    public function handle(): void
    {
        // Cari akun lama yang salah (Prepaid PPh 23)
        $oldAccount = Account::where('name', 'Prepaid PPh 23')->first();

        // Cari akun baru yang benar (Hutang PPh 23)
        $newAccount = Account::where('name', 'Hutang PPh 23')->first();

        if (! $oldAccount || ! $newAccount) {
            $this->error('Salah satu akun tidak ditemukan, pastikan kedua akun ada di database.');
            return;
        }

        // Ambil semua transaction ID yang berasal dari Bills
        $billTransactionIds = Bill::query()
            ->with('transactions')
            ->get()
            ->flatMap(fn($bill) => $bill->transactions->pluck('id'));

        // Cari journal entries Bills yang masih pakai akun lama
        $count = JournalEntry::query()
            ->whereIn('transaction_id', $billTransactionIds)
            ->where('account_id', $oldAccount->id)
            ->count();

        if ($count === 0) {
            $this->info('Tidak ada journal entries Bills yang perlu diubah.');
            return;
        }

        $this->info("Ditemukan {$count} journal entries yang akan diubah...");

        // Ganti account_id ke akun yang benar
        JournalEntry::query()
            ->whereIn('transaction_id', $billTransactionIds)
            ->where('account_id', $oldAccount->id)
            ->update(['account_id' => $newAccount->id]);

        $this->info('Berhasil! Journal entries sudah diupdate ke akun Hutang PPh 23.');
    }
}