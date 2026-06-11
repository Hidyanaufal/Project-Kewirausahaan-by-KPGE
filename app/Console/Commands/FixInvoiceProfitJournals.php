<?php

namespace App\Console\Commands;

use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Accounting\JournalEntryType;
use App\Models\Accounting\Account;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use App\Scopes\CurrentCompanyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixInvoiceProfitJournals extends Command
{
    protected $signature = 'fix:invoice-profit-journals
                            {--dry-run : Preview perubahan tanpa menyimpan ke database}
                            {--invoice-id= : Batasi satu invoice ID}
                            {--invoice-number= : Batasi nomor invoice}
                            {--company-id= : Batasi company ID (opsional; otomatis dari invoice jika satu record)}
                            {--stop-on-error : Hentikan pada invoice pertama yang gagal}';

    protected $description = 'Memperbaiki jurnal approval dan payment invoice dengan profit (AR dan PPN/WAPU) tanpa cancel invoice';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY-RUN MODE: Tidak ada data yang akan diubah.');
        }

        $query = Invoice::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->where('profit_total', '>', 0)
            ->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Cancelled])
            ->whereHas('approvalTransaction');

        if ($invoiceId = $this->option('invoice-id')) {
            $query->where('id', $invoiceId);
        }

        if ($invoiceNumber = $this->option('invoice-number')) {
            $query->where('invoice_number', $invoiceNumber);
        }

        if ($companyId = $this->option('company-id')) {
            $query->where('company_id', $companyId);
        }

        $invoices = $query->orderBy('company_id')->orderBy('id')->get();

        if ($invoices->isEmpty()) {
            $this->warn('Tidak ada invoice yang memenuhi kriteria.');

            return self::SUCCESS;
        }

        $this->info("Memeriksa {$invoices->count()} invoice dengan profit...");

        $previousCompanyId = session('current_company_id');
        $fixed = 0;
        $skipped = 0;
        $failed = 0;

        try {
            foreach ($invoices as $invoice) {
                session(['current_company_id' => $invoice->company_id]);

                if (! $invoice->needsProfitJournalFix()) {
                    $skipped++;
                    continue;
                }

                $before = $this->snapshotApprovalJournals($invoice);
                $expectedAr = $invoice->formatAmountToDefaultCurrency((int) $invoice->total);
                $expectedTax = $invoice->formatAmountToDefaultCurrency((int) $invoice->tax_total);

                $this->line("Invoice <comment>{$invoice->invoice_number}</comment> (ID {$invoice->id}, company {$invoice->company_id})");
                $this->line("  AR debit   : {$before['ar_debit']} → <info>{$expectedAr}</info>");
                $this->line("  Tax credit : {$before['tax_credit']} → <info>{$expectedTax}</info>");
                $this->line("  Payment trx: {$invoice->payments()->count()}");

                if ($isDryRun) {
                    $fixed++;

                    continue;
                }

                try {
                    DB::transaction(function () use ($invoice) {
                        $invoice->fixProfitJournalEntries();
                    });
                    $this->line('  <info>✓ Diperbaiki</info>');
                    $fixed++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("  ✗ Gagal: {$e->getMessage()}");
                    if ($this->option('stop-on-error')) {
                        return self::FAILURE;
                    }
                }
            }
        } finally {
            if ($previousCompanyId !== null) {
                session(['current_company_id' => $previousCompanyId]);
            } else {
                session()->forget('current_company_id');
            }
        }

        $this->newLine();
        $this->info("Selesai. Diperbaiki/dipreview: {$fixed}, dilewati (sudah benar): {$skipped}, gagal: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{ar_debit: int, tax_credit: int}
     */
    private function snapshotApprovalJournals(Invoice $invoice): array
    {
        $approvalTransaction = $invoice->approvalTransaction;
        $receivableAccount = Account::getAccountsReceivableAccount($invoice->company_id);

        return [
            'ar_debit' => (int) JournalEntry::query()
                ->where('transaction_id', $approvalTransaction->id)
                ->where('account_id', $receivableAccount->id)
                ->where('type', JournalEntryType::Debit)
                ->sum('amount'),
            'tax_credit' => (int) JournalEntry::query()
                ->where('transaction_id', $approvalTransaction->id)
                ->where('account_id', $invoice->salesTaxAccountId())
                ->where('type', JournalEntryType::Credit)
                ->sum('amount'),
        ];
    }
}
