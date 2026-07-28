<?php

namespace App\Console\Commands;

use App\Models\Accounting\Bill;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\Transaction;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data-repair: perbaiki transactions.posted_at di luar rentang wajar
 * (mis. tahun 0006 yang merusak DateRangeService / menu Reports).
 *
 * Default target: tanggal dokumen terkait (invoice/bill), fallback tahun --year
 * dengan mempertahankan bulan-hari dari posted_at corrupt.
 */
class FixInvalidTransactionPostedAt extends Command
{
    protected $signature = 'accounting:fix-invalid-posted-at
                            {--company-id= : Company ID (wajib kecuali --all-companies)}
                            {--all-companies : Jalankan untuk semua company}
                            {--before=2000-01-01 : Batas bawah wajar; posted_at lebih awal = diperbaiki}
                            {--year=2026 : Tahun pengganti jika tidak ada tanggal dokumen (pertahankan bulan-hari)}
                            {--date= : Paksa semua ke tanggal tetap Y-m-d (mengabaikan invoice/year fallback)}
                            {--dry-run : Preview tanpa menulis ke DB}
                            {--execute : Terapkan perubahan}';

    protected $description = 'Data repair: ubah transactions.posted_at invalid (mis. tahun 0006) ke tanggal 2026 / tanggal dokumen';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        $before = $this->parseDateOption('before', '2000-01-01');
        if ($before === null) {
            return self::FAILURE;
        }

        $forcedDate = null;
        if ($this->option('date')) {
            $forcedDate = $this->parseDateOption('date', (string) $this->option('date'));
            if ($forcedDate === null) {
                return self::FAILURE;
            }
        }

        $targetYear = (int) $this->option('year');
        if ($targetYear < 2000 || $targetYear > 2100) {
            $this->error('--year harus antara 2000–2100.');

            return self::FAILURE;
        }

        $companyIds = $this->resolveCompanyIds();
        if ($companyIds === []) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN: tidak ada perubahan tersimpan. Tambah --execute untuk menerapkan.');
        } else {
            $this->warn('LIVE: perubahan akan ditulis ke database.');
        }

        $this->info("Kriteria: posted_at < {$before}" . (
            $forcedDate
                ? " → paksa {$forcedDate}"
                : " → tanggal dokumen, else tahun {$targetYear} (MM-DD dipertahankan)"
        ));
        $this->newLine();

        $planned = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($companyIds as $companyId) {
            $company = Company::query()->withoutGlobalScopes()->find($companyId);
            $label = $company ? "{$company->name} (id={$companyId})" : "company id={$companyId}";
            $this->line("<fg=cyan>==== {$label} ====</>");

            $rows = DB::table('transactions')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->where('posted_at', '<', $before)
                ->orderBy('posted_at')
                ->orderBy('id')
                ->get([
                    'id',
                    'posted_at',
                    'description',
                    'transactionable_type',
                    'transactionable_id',
                ]);

            if ($rows->isEmpty()) {
                $this->line('  Tidak ada baris invalid.');
                $this->newLine();

                continue;
            }

            $table = [];

            foreach ($rows as $row) {
                $newDate = $this->resolveNewDate($row, $forcedDate, $targetYear);
                if ($newDate === null) {
                    $skipped++;
                    $table[] = [
                        $row->id,
                        (string) $row->posted_at,
                        '—',
                        'SKIP (tidak bisa resolve tanggal)',
                        $this->truncate((string) ($row->description ?? ''), 50),
                    ];

                    continue;
                }

                $planned++;
                $table[] = [
                    $row->id,
                    (string) $row->posted_at,
                    $newDate,
                    $dryRun ? 'would update' : 'updated',
                    $this->truncate((string) ($row->description ?? ''), 50),
                ];

                if (! $dryRun) {
                    Transaction::query()
                        ->withoutGlobalScopes()
                        ->whereKey($row->id)
                        ->update(['posted_at' => $newDate]);
                    $updated++;
                }
            }

            $this->table(
                ['id', 'posted_at lama', 'posted_at baru', 'status', 'description'],
                $table,
            );
            $this->newLine();
        }

        $this->info("Direncanakan: {$planned} | Diupdate: {$updated} | Skip: {$skipped}");
        if ($dryRun && $planned > 0) {
            $this->comment('Jalankan ulang dengan --execute untuk menyimpan.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  object{id:int,posted_at:string,transactionable_type:?string,transactionable_id:?int}  $row
     */
    private function resolveNewDate(object $row, ?string $forcedDate, int $targetYear): ?string
    {
        if ($forcedDate !== null) {
            return $forcedDate;
        }

        $docDate = $this->documentDate($row->transactionable_type, $row->transactionable_id);
        if ($docDate !== null) {
            return $docDate;
        }

        try {
            return Carbon::parse((string) $row->posted_at)
                ->year($targetYear)
                ->toDateString();
        } catch (\Throwable) {
            return sprintf('%d-01-01', $targetYear);
        }
    }

    private function documentDate(?string $type, mixed $id): ?string
    {
        if ($type === null || $id === null) {
            return null;
        }

        if ($type === Invoice::class || str_ends_with((string) $type, '\\Invoice')) {
            $date = DB::table('invoices')->where('id', $id)->value('date');

            return $date ? Carbon::parse((string) $date)->toDateString() : null;
        }

        if ($type === Bill::class || str_ends_with((string) $type, '\\Bill')) {
            $date = DB::table('bills')->where('id', $id)->value('date');

            return $date ? Carbon::parse((string) $date)->toDateString() : null;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function resolveCompanyIds(): array
    {
        if ($this->option('all-companies')) {
            return Company::query()
                ->withoutGlobalScopes()
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $companyId = $this->option('company-id');
        if ($companyId === null || $companyId === '') {
            $this->error('Wajib --company-id= atau --all-companies.');

            return [];
        }

        $id = (int) $companyId;
        $exists = Company::query()->withoutGlobalScopes()->whereKey($id)->exists();
        if (! $exists) {
            $this->error("Company id={$id} tidak ditemukan.");

            return [];
        }

        return [$id];
    }

    private function parseDateOption(string $name, string $fallback): ?string
    {
        $raw = $this->option($name) ?: $fallback;

        try {
            return Carbon::parse((string) $raw)->toDateString();
        } catch (\Throwable) {
            $this->error("Opsi --{$name} tidak valid: {$raw}");

            return null;
        }
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1) . '…';
    }
}
