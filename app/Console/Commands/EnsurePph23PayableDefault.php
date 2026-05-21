<?php

namespace App\Console\Commands;

use App\Enums\Accounting\AccountDefaultKey;
use App\Models\AccountDefault;
use App\Models\Accounting\Account;
use App\Models\Company;
use App\Scopes\CurrentCompanyScope;
use Illuminate\Console\Command;
use Throwable;

class EnsurePph23PayableDefault extends Command
{
    protected $signature = 'account:ensure-pph23-payable-default
                            {--company_id= : ID company yang akan di-update}
                            {--all-companies : Jalankan untuk semua company}
                            {--dry-run : Preview tanpa mengubah data}';

    protected $description = 'Memastikan COA default pph_23_payable (Hutang PPh 23) terkonfigurasi per company';

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

        $created = 0;
        $skipped = 0;

        foreach ($companyIds as $id) {
            $result = $this->ensureForCompany((int) $id, $dryRun);

            if ($result) {
                $created++;
            } else {
                $skipped++;
            }
        }

        $this->info("Selesai. Dikonfigurasi/diperbarui: {$created}. Dilewati: {$skipped}.");

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

        $this->error('company_id wajib diisi, atau gunakan --all-companies.');
        $this->line('Contoh: php artisan account:ensure-pph23-payable-default --company_id=1');

        return null;
    }

    protected function ensureForCompany(int $companyId, bool $dryRun): bool
    {
        $this->newLine();
        $this->info("Company ID: {$companyId}");

        $existing = AccountDefault::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->where('company_id', $companyId)
            ->where('key', AccountDefaultKey::PPh23Payable->value)
            ->first();

        if ($existing) {
            $account = Account::query()
                ->withoutGlobalScope(CurrentCompanyScope::class)
                ->find($existing->account_id);

            $this->line("  Sudah ada: pph_23_payable → [{$account?->id}] {$account?->name}");

            return false;
        }

        $payableAccount = Account::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->where('company_id', $companyId)
            ->where('name', 'Hutang PPh 23')
            ->first();

        if (! $payableAccount) {
            try {
                $payableAccount = Account::getAccountsPPh23Payable($companyId);
            } catch (Throwable) {
                $this->error('  Akun Hutang PPh 23 tidak ditemukan. Buat akun COA "Hutang PPh 23" terlebih dahulu.');

                return false;
            }
        }

        $this->line("  Akan set pph_23_payable → [{$payableAccount->id}] {$payableAccount->name}");

        if ($dryRun) {
            return true;
        }

        AccountDefault::query()->create([
            'company_id' => $companyId,
            'account_id' => $payableAccount->id,
            'key' => AccountDefaultKey::PPh23Payable->value,
        ]);

        $this->info('  Account default pph_23_payable berhasil dibuat.');

        return true;
    }
}
