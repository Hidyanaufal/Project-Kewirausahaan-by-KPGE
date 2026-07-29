<?php

namespace App\Services;

use App\Enums\Accounting\JournalEntryType;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;

class LegacyImportedInvoiceJournalService
{
    /**
     * Legacy workbook imports can produce WAPU approval journals split across
     * PPN WAPU and PPn Keluaran. Normalize approval tax before payment so the
     * standard payment journal flow remains unchanged.
     */
    public function preparePaidInvoiceForLegacyImport(Invoice $invoice): void
    {
        if (! $invoice->isWapu()) {
            return;
        }

        $this->consolidateSplitApprovalTaxEntries($invoice);
    }

    public function consolidateSplitApprovalTaxEntries(Invoice $invoice): void
    {
        if (! $invoice->isWapu()) {
            return;
        }

        $expectedTaxAccountId = $invoice->expectedHistoricalPaymentTaxAccountId();
        if (! $expectedTaxAccountId) {
            return;
        }

        $taxAccountIds = $invoice->historicalPaymentTaxAccountIds();
        if ($taxAccountIds === []) {
            return;
        }

        $approvalTransactionId = $invoice->approvalTransaction?->id;
        if (! $approvalTransactionId) {
            return;
        }

        $approvalEntries = JournalEntry::query()
            ->where('transaction_id', $approvalTransactionId)
            ->whereIn('account_id', $taxAccountIds)
            ->where('type', JournalEntryType::Credit)
            ->get();

        if ($approvalEntries->isEmpty()) {
            return;
        }

        $approvalAccountIds = $approvalEntries->pluck('account_id')->unique()->values();
        $needsConsolidation = $approvalAccountIds->count() > 1
            || ($approvalAccountIds->count() === 1 && (int) $approvalAccountIds->first() !== $expectedTaxAccountId);

        if (! $needsConsolidation) {
            return;
        }

        $approvalTaxTotal = (int) $approvalEntries->sum('amount');
        $description = $approvalEntries->first()?->description
            ?? "Tax entry for Invoice #{$invoice->invoice_number}";

        JournalEntry::query()
            ->whereIn('id', $approvalEntries->pluck('id'))
            ->delete();

        if ($approvalTaxTotal <= 0) {
            return;
        }

        JournalEntry::create([
            'company_id' => $invoice->company_id,
            'transaction_id' => $approvalTransactionId,
            'account_id' => $expectedTaxAccountId,
            'type' => JournalEntryType::Credit,
            'amount' => $approvalTaxTotal,
            'description' => $description,
        ]);
    }
}
