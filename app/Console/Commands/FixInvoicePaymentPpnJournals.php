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

    protected $description = 'Memperbaiki akun pajak pada jurnal payment invoice historis agar sesuai rule WAPU vs PPN keluaran';

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
                        $this->fixUnexpectedPaymentTaxEntries($invoice);
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
        $context = $this->buildPaymentTaxContext($invoice);

        if (! $context) {
            return [
                'affected_payment_count' => 0,
                'unexpected_tax_debit_total' => 0,
                'imbalanced_payment_count' => 0,
            ];
        }

        $affectedGroups = $context['groups']->filter(function (Collection $entries) use ($context) {
            $accountIds = $entries->pluck('account_id')->unique()->values();

            return $accountIds->count() > 1
                || ($accountIds->count() === 1 && (int) $accountIds->first() !== $context['expected_tax_account_id']);
        });

        $affectedPaymentIds = $affectedGroups->keys()->values();

        $unexpectedTaxDebitTotal = (int) $affectedGroups
            ->flatten(1)
            ->reject(fn (JournalEntry $entry) => (int) $entry->account_id === $context['expected_tax_account_id'])
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

    private function fixUnexpectedPaymentTaxEntries(Invoice $invoice): void
    {
        $context = $this->buildPaymentTaxContext($invoice);
        if (! $context) {
            return;
        }

        $expectedTaxAccountId = $context['expected_tax_account_id'];

        $context['groups']->each(function (Collection $entries) use ($expectedTaxAccountId) {
            $uniqueAccountIds = $entries->pluck('account_id')->unique()->values();

            if ($uniqueAccountIds->count() === 1 && (int) $uniqueAccountIds->first() !== $expectedTaxAccountId) {
                $entry = $entries->first();
                $entry->update([
                    'account_id' => $expectedTaxAccountId,
                ]);

                return;
            }

            $entries
                ->reject(fn (JournalEntry $entry) => (int) $entry->account_id === $expectedTaxAccountId)
                ->each(function (JournalEntry $entry) {
                    $entry->delete();
                });
        });
    }

    /**
     * @return array{expected_tax_account_id:int, groups: Collection<int, Collection<int, JournalEntry>>}|null
     */
    private function buildPaymentTaxContext(Invoice $invoice): ?array
    {
        $expectedTaxAccountId = $invoice->expectedHistoricalPaymentTaxAccountId();
        $paymentIds = $invoice->payments->pluck('id');

        if (! $expectedTaxAccountId || $paymentIds->isEmpty()) {
            return null;
        }

        $taxAccountIds = $invoice->historicalPaymentTaxAccountIds();

        if ($taxAccountIds === []) {
            return null;
        }

        $groups = JournalEntry::query()
            ->whereIn('transaction_id', $paymentIds)
            ->whereIn('account_id', $taxAccountIds)
            ->where('type', JournalEntryType::Debit)
            ->get()
            ->groupBy('transaction_id')
            ->filter(function (Collection $entries) use ($expectedTaxAccountId) {
                $accountIds = $entries->pluck('account_id')->unique()->values();

                return $accountIds->count() > 1
                    || ($accountIds->count() === 1 && (int) $accountIds->first() !== $expectedTaxAccountId);
            });

        return [
            'expected_tax_account_id' => $expectedTaxAccountId,
            'groups' => $groups,
        ];
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
