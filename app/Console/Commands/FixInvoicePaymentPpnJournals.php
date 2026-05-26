<?php

namespace App\Console\Commands;

use App\Enums\Accounting\JournalEntryType;
use App\Models\Accounting\Account;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
use App\Scopes\CurrentCompanyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FixInvoicePaymentPpnJournals extends Command
{
    protected $signature = 'fix:invoice-payment-ppn-journals
                            {--dry-run : Preview perubahan tanpa menyimpan data (wajib disertai --company-id)}
                            {--company-id= : Batasi ke satu company ID}
                            {--invoice-id= : Batasi ke satu invoice ID}
                            {--invoice-number= : Batasi ke satu nomor invoice}
                            {--all-companies : Proses seluruh company yang terdampak}
                            {--stop-on-error : Hentikan pada invoice pertama yang gagal}';

    protected $description = 'Memperbaiki jurnal payment invoice historis yang memiliki debit PPN WAPU dan PPN keluaran sekaligus';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $companyId = $this->option('company-id');
        $invoiceId = $this->option('invoice-id');
        $invoiceNumber = $this->option('invoice-number');
        $allCompanies = (bool) $this->option('all-companies');

        if ($isDryRun && ! $companyId) {
            $this->error('Opsi --dry-run wajib disertai --company-id agar preview tetap terfokus per company.');

            return self::FAILURE;
        }

        if ($allCompanies && $companyId) {
            $this->error('Gunakan salah satu: --company-id atau --all-companies.');

            return self::FAILURE;
        }

        if (! $allCompanies && ! $companyId && ! $invoiceId && ! $invoiceNumber) {
            $this->error('Tentukan target dengan --company-id, --invoice-id, --invoice-number, atau gunakan --all-companies.');

            return self::FAILURE;
        }

        if ($isDryRun) {
            $this->warn('DRY-RUN MODE: Tidak ada jurnal yang akan diubah.');
        }

        $query = Invoice::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->whereHas('payments');

        if ($invoiceId) {
            $query->where('id', $invoiceId);
        }

        if ($invoiceNumber) {
            $query->where('invoice_number', $invoiceNumber);
        }

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $invoices = $query
            ->with(['payments.bankAccount', 'client'])
            ->orderBy('company_id')
            ->orderBy('id')
            ->get();

        if ($invoices->isEmpty()) {
            $this->warn('Tidak ada invoice dengan payment yang ditemukan untuk kriteria tersebut.');

            return self::SUCCESS;
        }

        $this->info("Memeriksa {$invoices->count()} invoice dengan payment...");

        $previousCompanyId = session('current_company_id');
        $previewedOrFixed = 0;
        $skipped = 0;
        $failed = 0;

        try {
            foreach ($invoices as $invoice) {
                session(['current_company_id' => $invoice->company_id]);

                $invoice->refresh()->load(['payments.bankAccount', 'client']);

                if (! $invoice->needsHistoricalPaymentTaxAccountFix()) {
                    $skipped++;
                    continue;
                }

                $before = $this->snapshotPaymentJournals($invoice);
                $targetAccountName = $this->expectedTaxAccountName($invoice);

                $this->line("Invoice <comment>{$invoice->invoice_number}</comment> (ID {$invoice->id}, company {$invoice->company_id})");
                $this->line("  Pajak dipertahankan : {$targetAccountName}");
                $this->line("  Payment terdampak : {$before['affected_payment_count']}");
                $this->line("  Debit pajak salah : {$before['unexpected_tax_debit_total']} → <info>0</info>");
                $this->line("  Payment imbalance : {$before['imbalanced_payment_count']}");

                if ($isDryRun) {
                    $previewedOrFixed++;
                    continue;
                }

                try {
                    DB::transaction(function () use ($invoice) {
                        $this->deleteUnexpectedPaymentTaxEntries($invoice);
                    });

                    $invoice->refresh()->load('payments.bankAccount');
                    $after = $this->snapshotPaymentJournals($invoice);

                    if ($after['unexpected_tax_debit_total'] !== 0) {
                        throw new \RuntimeException('Debit pajak yang tidak sesuai masih tersisa setelah perbaikan.');
                    }

                    $this->line('  <info>✓ Diperbaiki</info>');
                    $previewedOrFixed++;
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
                app('session')->forget('current_company_id');
            }
        }

        $this->newLine();
        $label = $isDryRun ? 'Dipreview' : 'Diperbaiki';
        $this->info("Selesai. {$label}: {$previewedOrFixed}, dilewati: {$skipped}, gagal: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{affected_payment_count:int, unexpected_tax_debit_total:int, imbalanced_payment_count:int}
     */
    private function snapshotPaymentJournals(Invoice $invoice): array
    {
        $expectedTaxAccountId = $invoice->expectedHistoricalPaymentTaxAccountId();
        $wapuAccountId = Account::getAccountsWapuIfConfigured($invoice->company_id)?->id;
        $ppnAccountId = Account::getAccountsPPn($invoice->company_id)->id;
        $paymentIds = $invoice->payments->pluck('id');

        if ($paymentIds->isEmpty() || ! $expectedTaxAccountId || ! $wapuAccountId) {
            return [
                'affected_payment_count' => 0,
                'unexpected_tax_debit_total' => 0,
                'imbalanced_payment_count' => 0,
            ];
        }

        $taxDebitEntries = JournalEntry::query()
            ->whereIn('transaction_id', $paymentIds)
            ->whereIn('account_id', [$ppnAccountId, $wapuAccountId])
            ->where('type', JournalEntryType::Debit)
            ->get();

        $affectedPaymentIds = $taxDebitEntries
            ->groupBy('transaction_id')
            ->filter(fn (Collection $entries) => $entries->pluck('account_id')->unique()->count() >= 2)
            ->keys()
            ->values();

        $unexpectedTaxDebitTotal = (int) $taxDebitEntries
            ->whereIn('transaction_id', $affectedPaymentIds)
            ->reject(fn (JournalEntry $entry) => $entry->account_id === $expectedTaxAccountId)
            ->sum('amount');

        return [
            'affected_payment_count' => $affectedPaymentIds->count(),
            'unexpected_tax_debit_total' => $unexpectedTaxDebitTotal,
            'imbalanced_payment_count' => $this->countImbalancedPayments($invoice, $affectedPaymentIds),
        ];
    }

    private function expectedTaxAccountName(Invoice $invoice): string
    {
        $accountId = $invoice->expectedHistoricalPaymentTaxAccountId();

        if (! $accountId) {
            return 'Tanpa pajak';
        }

        return Account::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->where('company_id', $invoice->company_id)
            ->where('id', $accountId)
            ->value('name') ?? 'Tidak diketahui';
    }

    private function deleteUnexpectedPaymentTaxEntries(Invoice $invoice): void
    {
        $expectedTaxAccountId = $invoice->expectedHistoricalPaymentTaxAccountId();
        $wapuAccountId = Account::getAccountsWapuIfConfigured($invoice->company_id)?->id;

        if (! $expectedTaxAccountId || ! $wapuAccountId) {
            return;
        }

        $ppnAccountId = Account::getAccountsPPn($invoice->company_id)->id;
        $affectedPaymentIds = JournalEntry::query()
            ->whereIn('transaction_id', $invoice->payments()->select('id'))
            ->whereIn('account_id', [$ppnAccountId, $wapuAccountId])
            ->where('type', JournalEntryType::Debit)
            ->get()
            ->groupBy('transaction_id')
            ->filter(fn (Collection $entries) => $entries->pluck('account_id')->unique()->count() >= 2)
            ->keys();

        if ($affectedPaymentIds->isEmpty()) {
            return;
        }

        JournalEntry::query()
            ->whereIn('transaction_id', $affectedPaymentIds)
            ->whereIn('account_id', [$ppnAccountId, $wapuAccountId])
            ->where('type', JournalEntryType::Debit)
            ->where('account_id', '!=', $expectedTaxAccountId)
            ->delete();
    }

    private function countImbalancedPayments(Invoice $invoice, Collection $paymentIds): int
    {
        if ($paymentIds->isEmpty()) {
            return 0;
        }

        return $invoice->payments
            ->whereIn('id', $paymentIds)
            ->filter(fn (Transaction $payment) => ! $invoice->journalTransactionIsBalanced($payment))
            ->count();
    }
}
