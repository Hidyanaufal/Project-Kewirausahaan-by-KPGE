<?php

namespace App\Console\Commands;

use App\Enums\Accounting\InvoiceStatus;
use App\Models\Accounting\Invoice;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class VoidCancelledInvoiceJournals extends Command
{
    protected $signature = 'invoices:void-cancelled-journals
                            {--company_id= : ID company yang akan diproses}
                            {--all-companies : Jalankan untuk semua company}
                            {--invoice_id= : Hanya proses satu invoice (debug)}
                            {--dry-run : Preview tanpa mengubah data}';

    protected $description = 'Soft-delete transaksi dan jurnal untuk invoice cancelled lama yang masih punya transaksi aktif';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $allCompanies = (bool) $this->option('all-companies');
        $companyId = $this->option('company_id');
        $invoiceId = $this->option('invoice_id');

        if ($invoiceId) {
            return $this->processSingleInvoice((int) $invoiceId, $dryRun);
        }

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

        $totalProcessed = 0;
        $totalCandidates = 0;

        foreach ($companyIds as $id) {
            [$candidates, $processed] = $this->processCompany((int) $id, $dryRun);
            $totalCandidates += $candidates;
            $totalProcessed += $processed;
        }

        $this->newLine();
        $this->info("Selesai. Kandidat: {$totalCandidates}. Diproses: {$totalProcessed}.");

        return self::SUCCESS;
    }

    protected function processSingleInvoice(int $invoiceId, bool $dryRun): int
    {
        $invoice = Invoice::query()->find($invoiceId);

        if (! $invoice) {
            $this->error("Invoice #{$invoiceId} tidak ditemukan.");

            return self::FAILURE;
        }

        if ($invoice->status !== InvoiceStatus::Cancelled) {
            $this->error("Invoice #{$invoiceId} ({$invoice->invoice_number}) status bukan cancelled.");

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Mode dry-run: tidak ada perubahan yang disimpan.');
        }

        $transactionCount = $invoice->transactions()->count();

        if ($transactionCount === 0) {
            $this->line("Invoice #{$invoiceId} ({$invoice->invoice_number}) tidak punya transaksi aktif.");

            return self::SUCCESS;
        }

        $this->line("Invoice #{$invoiceId} ({$invoice->invoice_number}): {$transactionCount} transaksi aktif.");

        if ($dryRun) {
            return self::SUCCESS;
        }

        return $this->voidInvoice($invoice) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: int, 1: int} [candidates, processed]
     */
    protected function processCompany(int $companyId, bool $dryRun): array
    {
        $this->newLine();
        $this->info("Company ID: {$companyId}");

        $invoices = $this->candidateQuery($companyId)
            ->withCount('transactions')
            ->orderBy('id')
            ->get();

        $count = $invoices->count();

        if ($count === 0) {
            $this->line('  Tidak ada invoice cancelled dengan transaksi aktif.');

            return [0, 0];
        }

        $this->line("  Ditemukan {$count} invoice cancelled dengan transaksi aktif.");

        if ($dryRun) {
            $this->table(
                ['ID', 'Invoice Number', 'Transaksi Aktif', 'Amount Paid'],
                $invoices->map(fn (Invoice $invoice) => [
                    $invoice->id,
                    $invoice->invoice_number,
                    $invoice->transactions_count,
                    $invoice->amount_paid,
                ])->all(),
            );

            return [$count, 0];
        }

        $processed = 0;
        $errors = 0;

        foreach ($invoices as $invoice) {
            if ($this->voidInvoice($invoice)) {
                $processed++;
            } else {
                $errors++;
            }
        }

        if ($errors > 0) {
            $this->warn("  Gagal memproses {$errors} invoice.");
        }

        $this->info("  Berhasil void: {$processed} invoice.");

        return [$count, $processed];
    }

    /**
     * @return Builder<Invoice>
     */
    protected function candidateQuery(int $companyId): Builder
    {
        return Invoice::query()
            ->where('company_id', $companyId)
            ->where('status', InvoiceStatus::Cancelled)
            ->whereHas('transactions');
    }

    protected function voidInvoice(Invoice $invoice): bool
    {
        $transactionCount = $invoice->transactions()->count();
        $journalCount = $invoice->transactions()
            ->withCount('journalEntries')
            ->get()
            ->sum('journal_entries_count');

        try {
            DB::transaction(function () use ($invoice): void {
                $invoice->cancelWithAccountingVoid();
            });

            $this->line("  OK #{$invoice->id} {$invoice->invoice_number}: void {$transactionCount} transaksi, {$journalCount} baris jurnal.");

            return true;
        } catch (Throwable $e) {
            $this->error("  Gagal #{$invoice->id} {$invoice->invoice_number}: {$e->getMessage()}");

            return false;
        }
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

        $this->error('company_id wajib diisi (console tidak punya CurrentCompanyScope).');
        $this->line('Contoh: php artisan invoices:void-cancelled-journals --company_id=1 --dry-run');
        $this->line('Atau: php artisan invoices:void-cancelled-journals --all-companies --dry-run');

        return null;
    }
}
