<?php

namespace App\Console\Commands;

use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Accounting\JournalEntryType;
use App\Models\Accounting\Account;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
use App\Models\Company;
use App\Scopes\CurrentCompanyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Data-repair console only. Does not change Invoice posting business logic.
 *
 * Fixes historical invoice journals that make Trial Balance D≠C:
 * 1) Approval AR/tax mismatch (typical profit double-count residue)
 * 2) Payment journals where bank+tax ≠ AR (stale cash meta)
 */
class FixUnbalancedInvoicePaymentJournals extends Command
{
    protected $signature = 'accounting:fix-unbalanced-invoice-payment-journals
                            {--company-id= : Company ID (wajib kecuali --all-companies)}
                            {--all-companies : Jalankan untuk semua company}
                            {--invoice-id= : Batasi satu invoice ID}
                            {--dry-run : Preview tanpa menulis ke DB}
                            {--execute : Terapkan perubahan}';

    protected $description = 'Data repair: perbaiki jurnal approval/payment invoice yang D≠C tanpa mengubah logika bisnis Invoice';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        $companyIds = $this->resolveCompanyIds();
        if ($companyIds === []) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN: tidak ada perubahan tersimpan. Tambah --execute untuk menerapkan.');
        } else {
            $this->warn('LIVE: perubahan akan ditulis ke database.');
        }

        $previousCompanyId = session('current_company_id');

        $fixedApprovals = 0;
        $fixedPayments = 0;
        $skipped = 0;
        $failed = 0;

        try {
            foreach ($companyIds as $companyId) {
                $company = Company::query()->findOrFail($companyId);
                $this->newLine();
                $this->info("==== Company {$companyId} | {$company->name} ====");
                session(['current_company_id' => $companyId]);

                $invoices = $this->candidateInvoices($companyId);

                foreach ($invoices as $invoice) {
                    session(['current_company_id' => $companyId]);
                    $invoice->loadMissing(['lineItems.offering', 'client', 'payments.bankAccount', 'approvalTransaction']);

                    $needsApproval = $this->approvalNeedsRepair($invoice);
                    $unbalancedPayments = $this->unbalancedPaymentIds($invoice);

                    if (! $needsApproval && $unbalancedPayments === []) {
                        $skipped++;

                        continue;
                    }

                    $this->line("Invoice {$invoice->invoice_number} (ID {$invoice->id})"
                        .($needsApproval ? ' [approval]' : '')
                        .($unbalancedPayments !== [] ? ' [payment:'.implode(',', $unbalancedPayments).']' : ''));

                    if ($dryRun) {
                        if ($needsApproval) {
                            $fixedApprovals++;
                        }
                        $fixedPayments += count($unbalancedPayments);

                        continue;
                    }

                    try {
                        DB::transaction(function () use ($invoice, $needsApproval, &$fixedApprovals, &$fixedPayments) {
                            if ($needsApproval) {
                                $this->repairApprovalJournals($invoice);
                                $fixedApprovals++;
                            }

                            $invoice->refresh()->load(['payments.bankAccount', 'approvalTransaction', 'client']);

                            foreach ($invoice->payments()->with('bankAccount')->orderBy('id')->get() as $payment) {
                                if ($this->transactionIsBalanced($payment->id) && ! $needsApproval) {
                                    continue;
                                }
                                $this->repairPaymentJournals($invoice, $payment);
                                $fixedPayments++;
                            }

                            foreach ($invoice->transactions()->get() as $txn) {
                                if (! $this->transactionIsBalanced($txn->id)) {
                                    throw new \RuntimeException(
                                        "Txn #{$txn->id} masih D≠C setelah repair."
                                    );
                                }
                            }
                        });
                        $this->line('  <info>✓ repaired</info>');
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error("  ✗ {$e->getMessage()}");
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
        $this->info(sprintf(
            'Selesai. Approvals=%d payments=%d skipped=%d failed=%d%s',
            $fixedApprovals,
            $fixedPayments,
            $skipped,
            $failed,
            $dryRun ? ' (dry-run)' : ''
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveCompanyIds(): array
    {
        if ($this->option('all-companies')) {
            return Company::query()->orderBy('id')->pluck('id')->all();
        }

        $companyId = $this->option('company-id');
        if (! $companyId) {
            $this->error('Wajib --company-id= atau --all-companies.');

            return [];
        }

        if (! Company::query()->whereKey((int) $companyId)->exists()) {
            $this->error("Company {$companyId} tidak ditemukan.");

            return [];
        }

        return [(int) $companyId];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Invoice>
     */
    private function candidateInvoices(int $companyId)
    {
        $query = Invoice::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->where('company_id', $companyId)
            ->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Cancelled])
            ->whereHas('approvalTransaction')
            ->orderBy('id');

        if ($invoiceId = $this->option('invoice-id')) {
            $query->where('id', (int) $invoiceId);
        }

        return $query->get();
    }

    private function approvalNeedsRepair(Invoice $invoice): bool
    {
        $approval = $invoice->approvalTransaction;
        if (! $approval) {
            return false;
        }

        $receivable = Account::getAccountsReceivableAccount($invoice->company_id);
        $arDebit = (int) JournalEntry::query()
            ->where('transaction_id', $approval->id)
            ->where('account_id', $receivable->id)
            ->where('type', JournalEntryType::Debit)
            ->sum('amount');

        $expectedAr = $invoice->formatAmountToDefaultCurrency((int) $invoice->total);
        if ($arDebit !== $expectedAr) {
            return true;
        }

        $taxAccountIds = array_values(array_filter([
            $invoice->historicalPpnTaxAccountId(),
            $invoice->historicalWapuTaxAccountId(),
        ]));

        if ($taxAccountIds === [] || (int) $invoice->tax_total <= 0) {
            return ! $this->transactionIsBalanced($approval->id);
        }

        $taxCredit = (int) JournalEntry::query()
            ->where('transaction_id', $approval->id)
            ->whereIn('account_id', $taxAccountIds)
            ->where('type', JournalEntryType::Credit)
            ->sum('amount');

        $expectedTax = $invoice->formatAmountToDefaultCurrency((int) $invoice->tax_total);

        return $taxCredit !== $expectedTax || ! $this->transactionIsBalanced($approval->id);
    }

    /**
     * @return list<int>
     */
    private function unbalancedPaymentIds(Invoice $invoice): array
    {
        $ids = [];
        foreach ($invoice->payments as $payment) {
            if (! $this->transactionIsBalanced($payment->id)) {
                $ids[] = (int) $payment->id;
            }
        }

        return $ids;
    }

    private function transactionIsBalanced(int $transactionId): bool
    {
        $row = DB::table('journal_entries')
            ->where('transaction_id', $transactionId)
            ->whereNull('deleted_at')
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as d,
                COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as c
            ")
            ->first();

        return (int) ($row->d ?? 0) === (int) ($row->c ?? 0);
    }

    /**
     * Rebuild approval journals from invoice totals (simple balanced set).
     * Keeps revenue on the dominant line income account (or profit default leaf).
     */
    private function repairApprovalJournals(Invoice $invoice): void
    {
        $approval = $invoice->approvalTransaction;
        if (! $approval) {
            throw new \RuntimeException('Approval transaction tidak ada.');
        }

        JournalEntry::query()
            ->where('transaction_id', $approval->id)
            ->delete();

        $companyId = (int) $invoice->company_id;
        $receivable = Account::getAccountsReceivableAccount($companyId);
        $taxAccountId = $invoice->isWapu()
            ? ($invoice->historicalWapuTaxAccountId() ?? $invoice->historicalPpnTaxAccountId())
            : $invoice->historicalPpnTaxAccountId();

        $total = $invoice->formatAmountToDefaultCurrency((int) $invoice->total);
        $tax = $invoice->formatAmountToDefaultCurrency((int) $invoice->tax_total);
        $revenue = $total - $tax;

        if ($total <= 0) {
            throw new \RuntimeException('Invoice total tidak valid untuk repair approval.');
        }

        $incomeAccountId = $this->resolveIncomeAccountId($invoice);
        $clientName = $invoice->client?->name ?? 'Client';
        $baseDescription = "{$clientName} : Invoice #{$invoice->invoice_number}";

        JournalEntry::create([
            'company_id' => $companyId,
            'transaction_id' => $approval->id,
            'account_id' => $receivable->id,
            'type' => JournalEntryType::Debit,
            'amount' => $total,
            'description' => $baseDescription,
        ]);

        if ($tax > 0 && $taxAccountId) {
            JournalEntry::create([
                'company_id' => $companyId,
                'transaction_id' => $approval->id,
                'account_id' => $taxAccountId,
                'type' => JournalEntryType::Credit,
                'amount' => $tax,
                'description' => "Tax entry for Invoice #{$invoice->invoice_number}",
            ]);
        }

        if ($revenue > 0) {
            JournalEntry::create([
                'company_id' => $companyId,
                'transaction_id' => $approval->id,
                'account_id' => $incomeAccountId,
                'type' => JournalEntryType::Credit,
                'amount' => $revenue,
                'description' => $baseDescription,
            ]);
        }

        if (! $this->transactionIsBalanced($approval->id)) {
            throw new \RuntimeException('Approval masih D≠C setelah rebuild sederhana.');
        }
    }

    private function resolveIncomeAccountId(Invoice $invoice): int
    {
        $invoice->loadMissing('lineItems.offering');

        foreach ($invoice->lineItems as $line) {
            $incomeId = $line->offering?->income_account_id;
            if ($incomeId) {
                $account = Account::query()
                    ->withoutGlobalScope(CurrentCompanyScope::class)
                    ->find($incomeId);
                // Prefer leaf (no children)
                if ($account && ! Account::query()
                    ->withoutGlobalScope(CurrentCompanyScope::class)
                    ->where('parent_id', $account->id)
                    ->exists()) {
                    return (int) $account->id;
                }
                if ($account) {
                    $leaf = Account::query()
                        ->withoutGlobalScope(CurrentCompanyScope::class)
                        ->where('parent_id', $account->id)
                        ->orderByRaw('LENGTH(code), code')
                        ->first();
                    if ($leaf) {
                        return (int) $leaf->id;
                    }
                }
            }
        }

        try {
            return (int) Account::getAccountsProfit($invoice->company_id)->id;
        } catch (Throwable) {
            throw new \RuntimeException('Tidak menemukan akun pendapatan/profit untuk repair approval.');
        }
    }

    /**
     * Rebuild one payment journal so bank + tax debits = AR credit (calculated cash).
     */
    private function repairPaymentJournals(Invoice $invoice, Transaction $payment): void
    {
        $companyId = (int) $invoice->company_id;
        $receivable = Account::getAccountsReceivableAccount($companyId);
        $pph23 = Account::getAccountsPPh23($companyId);
        $wapuId = $invoice->isWapu() ? $invoice->historicalWapuTaxAccountId() : null;
        $ppnId = $invoice->historicalPpnTaxAccountId();
        $taxAccountIds = array_values(array_filter([$wapuId, $ppnId]));

        $approval = $invoice->approvalTransaction;
        if (! $approval) {
            throw new \RuntimeException('Approval transaction tidak ada untuk rebuild payment.');
        }

        $approvalEntries = JournalEntry::query()
            ->where('transaction_id', $approval->id)
            ->get();

        $arApproval = (int) $approvalEntries
            ->where('account_id', $receivable->id)
            ->where('type', JournalEntryType::Debit)
            ->sum('amount');

        $previousPaymentIds = Transaction::query()
            ->where('transactionable_type', Invoice::class)
            ->where('transactionable_id', $invoice->id)
            ->where('is_payment', true)
            ->where('id', '<', $payment->id)
            ->pluck('id');

        $previousNetAr = (int) JournalEntry::query()
            ->whereIn('transaction_id', $previousPaymentIds)
            ->where('account_id', $receivable->id)
            ->where('type', JournalEntryType::Credit)
            ->sum('amount')
            - (int) JournalEntry::query()
                ->whereIn('transaction_id', $previousPaymentIds)
                ->where('account_id', $receivable->id)
                ->where('type', JournalEntryType::Debit)
                ->sum('amount');

        $remainingReceivable = $arApproval - $previousNetAr;

        JournalEntry::query()
            ->where('transaction_id', $payment->id)
            ->where(function ($q) use ($taxAccountIds, $receivable, $pph23, $payment) {
                $q->whereIn('account_id', $taxAccountIds ?: [0])
                    ->orWhere('account_id', $receivable->id)
                    ->orWhere('account_id', $pph23->id)
                    ->orWhere('account_id', $payment->bankAccount?->account_id);
            })
            ->delete();

        $clientName = $invoice->client?->name ?? 'Client';
        $desc = "Invoice #{$invoice->invoice_number}: Payment from {$clientName}";
        $taxDebitTotal = 0;

        if ($invoice->isWapu() && $wapuId) {
            $wapuAmount = (int) $approvalEntries
                ->where('account_id', $wapuId)
                ->where('type', JournalEntryType::Credit)
                ->sum('amount');

            // Single-payment invoices take full approval WAPU; multi-payment: proportional by remaining AR share
            $paymentCount = $invoice->payments()->count();
            if ($paymentCount > 1 && $arApproval > 0) {
                $wapuAmount = (int) round($wapuAmount * ($remainingReceivable / $arApproval));
            }

            if ($wapuAmount > 0) {
                JournalEntry::create([
                    'company_id' => $companyId,
                    'transaction_id' => $payment->id,
                    'account_id' => $wapuId,
                    'type' => JournalEntryType::Debit,
                    'amount' => $wapuAmount,
                    'description' => $desc,
                ]);
                $taxDebitTotal += $wapuAmount;
            }
        }

        $pph23Amount = (int) (
            $payment->meta['pph23_cents']
            ?? $invoice->pph23
            ?? 0
        );
        if ($pph23Amount > 0) {
            JournalEntry::create([
                'company_id' => $companyId,
                'transaction_id' => $payment->id,
                'account_id' => $pph23->id,
                'type' => JournalEntryType::Debit,
                'amount' => $pph23Amount,
                'description' => $desc,
            ]);
            $taxDebitTotal += $pph23Amount;
        }

        $cashAmount = $remainingReceivable - $taxDebitTotal;
        $bankAccountId = $payment->bankAccount?->account_id;
        if ($bankAccountId && $cashAmount !== 0) {
            JournalEntry::create([
                'company_id' => $companyId,
                'transaction_id' => $payment->id,
                'account_id' => $bankAccountId,
                'type' => $cashAmount > 0 ? JournalEntryType::Debit : JournalEntryType::Credit,
                'amount' => abs($cashAmount),
                'description' => $desc,
            ]);
        }

        JournalEntry::create([
            'company_id' => $companyId,
            'transaction_id' => $payment->id,
            'account_id' => $receivable->id,
            'type' => JournalEntryType::Credit,
            'amount' => $remainingReceivable,
            'description' => $desc,
        ]);

        if (! $this->transactionIsBalanced($payment->id)) {
            throw new \RuntimeException("Payment txn #{$payment->id} masih D≠C setelah repair.");
        }
    }
}
