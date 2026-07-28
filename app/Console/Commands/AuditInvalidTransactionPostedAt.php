<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only audit: temukan transactions.posted_at di luar rentang wajar
 * (penyebab DateRangeService meledak, mis. tahun 0006 di KPPE).
 */
class AuditInvalidTransactionPostedAt extends Command
{
    protected $signature = 'accounting:audit-invalid-posted-at
                            {--company-id= : Batasi ke satu company ID}
                            {--all-companies : Audit semua company}
                            {--before=2000-01-01 : Batas bawah wajar (Y-m-d); posted_at lebih awal = invalid}
                            {--after= : Batas atas wajar (Y-m-d); default: hari ini + 1 tahun}
                            {--limit=50 : Maks baris detail per company (0 = semua)}
                            {--csv= : Path file CSV untuk ekspor detail (opsional)}';

    protected $description = 'Audit read-only: transaksi dengan posted_at di luar rentang wajar (mis. tahun 0006)';

    public function handle(): int
    {
        $before = $this->parseDateOption('before', '2000-01-01');
        $after = $this->option('after')
            ? $this->parseDateOption('after', (string) $this->option('after'))
            : company_today()->addYear()->toDateString();

        if ($before === null || $after === null) {
            return self::FAILURE;
        }

        $companyIds = $this->resolveCompanyIds();
        if ($companyIds === []) {
            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $csvPath = $this->option('csv');
        $csvRows = [];

        $this->info("Audit posted_at invalid — sebelum {$before} ATAU setelah {$after}");
        $this->newLine();

        $grandTotal = 0;

        foreach ($companyIds as $companyId) {
            $company = Company::query()->withoutGlobalScopes()->find($companyId);
            $label = $company ? "{$company->name} (id={$companyId})" : "company id={$companyId}";

            $base = DB::table('transactions')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($before, $after) {
                    $q->where('posted_at', '<', $before)
                        ->orWhere('posted_at', '>', $after);
                });

            $count = (clone $base)->count();
            $minRaw = DB::table('transactions')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->min('posted_at');
            $minSane = DB::table('transactions')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->where('posted_at', '>=', $before)
                ->where('posted_at', '<=', $after)
                ->min('posted_at');

            $txTotal = DB::table('transactions')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->count();

            $this->line("<fg=cyan>{$label}</>");
            $this->line("  total transactions : {$txTotal}");
            $this->line("  invalid posted_at  : {$count}");
            $this->line("  MIN posted_at (raw): " . ($minRaw ?? '—'));
            $this->line("  MIN posted_at (sane ≥ {$before}): " . ($minSane ?? '—'));

            if ($minRaw) {
                $monthsRaw = $this->estimateMonthSpan((string) $minRaw);
                $monthsSane = $minSane ? $this->estimateMonthSpan((string) $minSane) : null;
                $this->line("  DateRange iterasi ≈ {$monthsRaw} bulan (raw)" . (
                    $monthsSane !== null ? ", ≈ {$monthsSane} bulan (sane)" : ''
                ));
            }

            $grandTotal += $count;

            if ($count === 0) {
                $this->newLine();

                continue;
            }

            $query = (clone $base)
                ->orderBy('posted_at')
                ->orderBy('id')
                ->select([
                    'id',
                    'company_id',
                    'posted_at',
                    'type',
                    'description',
                    'amount',
                    'is_payment',
                    'transactionable_type',
                    'transactionable_id',
                ]);

            if ($limit > 0) {
                $query->limit($limit);
            }

            $rows = $query->get();

            $table = $rows->map(fn ($row) => [
                $row->id,
                (string) $row->posted_at,
                $row->type,
                $row->is_payment ? 'yes' : 'no',
                $this->shortMorph((string) ($row->transactionable_type ?? ''), $row->transactionable_id),
                $this->truncate((string) ($row->description ?? ''), 60),
                $row->amount,
            ])->all();

            $this->table(
                ['id', 'posted_at', 'type', 'payment', 'related', 'description', 'amount'],
                $table,
            );

            if ($limit > 0 && $count > $limit) {
                $this->warn("  Menampilkan {$limit} dari {$count} baris. Gunakan --limit=0 untuk semua, atau --csv=.");
            }

            foreach ($rows as $row) {
                $csvRows[] = [
                    'company_id' => $row->company_id,
                    'company' => $company?->name ?? '',
                    'transaction_id' => $row->id,
                    'posted_at' => $row->posted_at,
                    'type' => $row->type,
                    'is_payment' => $row->is_payment ? 1 : 0,
                    'transactionable_type' => $row->transactionable_type,
                    'transactionable_id' => $row->transactionable_id,
                    'description' => $row->description,
                    'amount' => $row->amount,
                ];
            }

            // If CSV wants all rows but table was limited, fetch remaining for CSV only.
            if ($csvPath && $limit > 0 && $count > $limit) {
                $rest = (clone $base)
                    ->orderBy('posted_at')
                    ->orderBy('id')
                    ->offset($limit)
                    ->select([
                        'id',
                        'company_id',
                        'posted_at',
                        'type',
                        'description',
                        'amount',
                        'is_payment',
                        'transactionable_type',
                        'transactionable_id',
                    ])
                    ->get();

                foreach ($rest as $row) {
                    $csvRows[] = [
                        'company_id' => $row->company_id,
                        'company' => $company?->name ?? '',
                        'transaction_id' => $row->id,
                        'posted_at' => $row->posted_at,
                        'type' => $row->type,
                        'is_payment' => $row->is_payment ? 1 : 0,
                        'transactionable_type' => $row->transactionable_type,
                        'transactionable_id' => $row->transactionable_id,
                        'description' => $row->description,
                        'amount' => $row->amount,
                    ];
                }
            }

            $this->newLine();
        }

        $this->info("Total invalid across selected companies: {$grandTotal}");

        if ($csvPath) {
            $this->writeCsv((string) $csvPath, $csvRows);
            $this->info('CSV ditulis: ' . $csvPath . ' (' . count($csvRows) . ' baris)');
        }

        return self::SUCCESS;
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
        } catch (\Throwable $e) {
            $this->error("Opsi --{$name} tidak valid: {$raw}");

            return null;
        }
    }

    private function estimateMonthSpan(string $from): int
    {
        try {
            $start = Carbon::parse($from)->startOfMonth();
            $end = company_today()->startOfMonth();

            return (int) max(0, $start->diffInMonths($end));
        } catch (\Throwable) {
            return -1;
        }
    }

    private function shortMorph(string $type, mixed $id): string
    {
        if ($type === '' || $id === null) {
            return '—';
        }

        $short = class_basename($type);

        return "{$short}#{$id}";
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1) . '…';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fh = fopen($path, 'w');
        if ($fh === false) {
            $this->error("Gagal membuka CSV: {$path}");

            return;
        }

        $headers = [
            'company_id',
            'company',
            'transaction_id',
            'posted_at',
            'type',
            'is_payment',
            'transactionable_type',
            'transactionable_id',
            'description',
            'amount',
        ];
        fputcsv($fh, $headers);

        foreach ($rows as $row) {
            fputcsv($fh, array_map(fn ($key) => $row[$key] ?? '', $headers));
        }

        fclose($fh);
    }
}
