<?php

namespace App\Console\Commands;

use App\Models\Accounting\Bill;
use App\Models\Accounting\Invoice;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data-repair: perbaiki transactions.posted_at di luar rentang wajar
 * (mis. tahun 0006 yang merusak DateRangeService / menu Reports).
 *
 * Default target: tanggal dokumen terkait (invoice/bill) HANYA jika tanggal dokumen
 * juga wajar; jika dokumen ikut corrupt → rewrite tahun (--year) dengan MM-DD sama.
 */
class FixInvalidTransactionPostedAt extends Command
{
    protected $signature = 'accounting:fix-invalid-posted-at
                            {--company-id= : Company ID (wajib kecuali --all-companies)}
                            {--all-companies : Jalankan untuk semua company}
                            {--before=2000-01-01 : Batas bawah wajar; posted_at lebih awal = diperbaiki}
                            {--year=2026 : Tahun pengganti jika tanggal dokumen tidak wajar (pertahankan bulan-hari)}
                            {--date= : Paksa semua ke tanggal tetap Y-m-d (mengabaikan invoice/year fallback)}
                            {--fix-documents : Juga perbaiki invoices.date / bills.date jika ikut invalid}
                            {--dry-run : Preview tanpa menulis ke DB}
                            {--execute : Terapkan perubahan}';

    protected $description = 'Data repair: ubah transactions.posted_at invalid (mis. tahun 0006) ke tanggal 2026 / tanggal dokumen wajar';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');
        $fixDocuments = (bool) $this->option('fix-documents');

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
            if (! $this->isSaneDate($forcedDate, $before)) {
                $this->error("--date={$forcedDate} masih di bawah batas --before={$before}.");

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
                : " → tanggal dokumen wajar, else tahun {$targetYear} (MM-DD dipertahankan)"
        ));
        if ($fixDocuments) {
            $this->info('Opsi --fix-documents aktif: invoice/bill date invalid ikut diperbaiki.');
        }
        $this->newLine();

        $planned = 0;
        $updated = 0;
        $docsFixed = 0;
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
                $oldDate = Carbon::parse((string) $row->posted_at)->toDateString();
                $resolved = $this->resolveNewDate($row, $forcedDate, $targetYear, $before);

                if ($resolved === null) {
                    $skipped++;
                    $table[] = [
                        $row->id,
                        $oldDate,
                        '—',
                        '—',
                        'SKIP (tidak bisa resolve tanggal)',
                        $this->truncate((string) ($row->description ?? ''), 40),
                    ];

                    continue;
                }

                [$newDate, $source] = $resolved;

                if ($newDate === $oldDate) {
                    $skipped++;
                    $table[] = [
                        $row->id,
                        $oldDate,
                        $newDate,
                        $source,
                        'SKIP (hasil sama; dokumen juga corrupt?)',
                        $this->truncate((string) ($row->description ?? ''), 40),
                    ];

                    continue;
                }

                $planned++;
                $table[] = [
                    $row->id,
                    $oldDate,
                    $newDate,
                    $source,
                    $dryRun ? 'would update' : 'updated',
                    $this->truncate((string) ($row->description ?? ''), 40),
                ];

                if (! $dryRun) {
                    // Pakai query builder agar tidak kena cast/mutator Eloquent yang aneh di tahun 0006.
                    DB::table('transactions')
                        ->where('id', $row->id)
                        ->update([
                            'posted_at' => $newDate,
                            'updated_at' => now(),
                        ]);
                    $updated++;
                }

                if ($fixDocuments) {
                    $docsFixed += $this->fixRelatedDocumentDate(
                        $row->transactionable_type,
                        $row->transactionable_id,
                        $newDate,
                        $before,
                        $dryRun,
                    );
                }
            }

            $this->table(
                ['id', 'posted_at lama', 'posted_at baru', 'sumber', 'status', 'description'],
                $table,
            );
            $this->newLine();
        }

        $this->info("Direncanakan: {$planned} | Diupdate: {$updated} | Skip: {$skipped}" . (
            $fixDocuments ? " | Dokumen diperbaiki: {$docsFixed}" : ''
        ));
        if ($dryRun && $planned > 0) {
            $this->comment('Jalankan ulang dengan --execute untuk menyimpan.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  object{id:int,posted_at:string,transactionable_type:?string,transactionable_id:?int}  $row
     * @return array{0: string, 1: string}|null
     */
    private function resolveNewDate(object $row, ?string $forcedDate, int $targetYear, string $before): ?array
    {
        if ($forcedDate !== null) {
            return [$forcedDate, 'forced --date'];
        }

        $docDate = $this->documentDate($row->transactionable_type, $row->transactionable_id);
        if ($docDate !== null && $this->isSaneDate($docDate, $before)) {
            return [$docDate, 'document'];
        }

        if ($docDate !== null && ! $this->isSaneDate($docDate, $before)) {
            // Dokumen ikut corrupt (kasus server KPPE): rewrite tahun dari MM-DD yang sama.
            $rewritten = $this->rewriteYear($docDate, $targetYear);
            if ($rewritten !== null && $this->isSaneDate($rewritten, $before)) {
                return [$rewritten, "document year→{$targetYear}"];
            }
        }

        $fromPosted = $this->rewriteYear((string) $row->posted_at, $targetYear);
        if ($fromPosted !== null && $this->isSaneDate($fromPosted, $before)) {
            return [$fromPosted, "posted_at year→{$targetYear}"];
        }

        $fallback = sprintf('%d-01-01', $targetYear);

        return [$fallback, 'fallback Jan 1'];
    }

    private function rewriteYear(string $date, int $targetYear): ?string
    {
        try {
            $parsed = Carbon::parse($date);

            // Hindari Carbon::year() pada tahun ekstrem; set komponen eksplisit.
            return Carbon::createSafe($targetYear, $parsed->month, $parsed->day)?->toDateString()
                ?? Carbon::create($targetYear, $parsed->month, min($parsed->day, 28))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isSaneDate(string $date, string $before): bool
    {
        try {
            return Carbon::parse($date)->toDateString() >= $before;
        } catch (\Throwable) {
            return false;
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

    private function fixRelatedDocumentDate(
        ?string $type,
        mixed $id,
        string $newDate,
        string $before,
        bool $dryRun,
    ): int {
        if ($type === null || $id === null) {
            return 0;
        }

        $table = null;
        if ($type === Invoice::class || str_ends_with((string) $type, '\\Invoice')) {
            $table = 'invoices';
        } elseif ($type === Bill::class || str_ends_with((string) $type, '\\Bill')) {
            $table = 'bills';
        }

        if ($table === null) {
            return 0;
        }

        $current = DB::table($table)->where('id', $id)->value('date');
        if ($current === null) {
            return 0;
        }

        $currentDate = Carbon::parse((string) $current)->toDateString();
        if ($this->isSaneDate($currentDate, $before)) {
            return 0;
        }

        if ($dryRun) {
            $this->line("  [doc] would fix {$table}#{$id} date {$currentDate} → {$newDate}");

            return 1;
        }

        $payload = [
            'date' => $newDate,
            'updated_at' => now(),
        ];

        // Samakan due_date jika ikut invalid (invoice).
        if ($table === 'invoices') {
            $due = DB::table('invoices')->where('id', $id)->value('due_date');
            if ($due !== null && ! $this->isSaneDate(Carbon::parse((string) $due)->toDateString(), $before)) {
                $payload['due_date'] = $newDate;
            }
        }

        DB::table($table)->where('id', $id)->update($payload);
        $this->line("  [doc] fixed {$table}#{$id} date {$currentDate} → {$newDate}");

        return 1;
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
