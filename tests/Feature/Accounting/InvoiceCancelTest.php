<?php

use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Accounting\JournalEntryType;
use App\Models\Accounting\Account;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
use App\Utilities\Currency\CurrencyAccessor;

beforeEach(function () {
    $this->defaultCurrency = CurrencyAccessor::getDefaultCurrency();
    $this->withOfferings();
});

test('cancel invoice soft-deletes linked transactions and journal entries', function () {
    $invoice = Invoice::withoutEvents(function () {
        return Invoice::factory()
            ->state([
                'company_id' => $this->testCompany->id,
                'subtotal' => 10000,
                'tax_total' => 0,
                'discount_total' => 0,
                'total' => 10000,
                'amount_paid' => 0,
            ])
            ->create();
    });

    $invoice->refresh();

    $accounts = Account::query()
        ->where('company_id', $invoice->company_id)
        ->limit(2)
        ->get();

    expect($accounts)->toHaveCount(2);

    $transaction = Transaction::factory()
        ->asJournal(1000)
        ->create([
            'company_id' => $invoice->company_id,
            'transactionable_id' => $invoice->id,
            'transactionable_type' => $invoice->getMorphClass(),
            'posted_at' => now()->toDateString(),
            'description' => 'Invoice cancel test journal',
        ]);

    $transaction->journalEntries()->create([
        'company_id' => $invoice->company_id,
        'account_id' => $accounts[0]->id,
        'type' => JournalEntryType::Debit,
        'amount' => 1000,
        'description' => 'Debit test',
    ]);
    $transaction->journalEntries()->create([
        'company_id' => $invoice->company_id,
        'account_id' => $accounts[1]->id,
        'type' => JournalEntryType::Credit,
        'amount' => 1000,
        'description' => 'Credit test',
    ]);

    $transaction->refresh();

    expect($transaction->journalEntries()->count())->toBe(2);

    $journalEntryIds = $transaction->journalEntries->pluck('id')->all();

    $invoice->cancelWithAccountingVoid();
    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Cancelled)
        ->and((int) $invoice->amount_paid)->toBe(0)
        ->and($invoice->paid_at)->toBeNull()
        ->and($invoice->transactions()->count())->toBe(0);

    foreach ($journalEntryIds as $journalEntryId) {
        expect(JournalEntry::query()->whereKey($journalEntryId)->exists())->toBeFalse()
            ->and(JournalEntry::onlyTrashed()->whereKey($journalEntryId)->exists())->toBeTrue();
    }
});

test('cancel invoice with no transactions sets cancelled and zero paid', function () {
    $invoice = Invoice::withoutEvents(function () {
        return Invoice::factory()
            ->state([
                'company_id' => $this->testCompany->id,
                'status' => InvoiceStatus::Draft,
                'subtotal' => 5000,
                'tax_total' => 0,
                'discount_total' => 0,
                'total' => 5000,
                'amount_paid' => 0,
            ])
            ->create();
    });

    $invoice->cancelWithAccountingVoid();

    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Cancelled)
        ->and((int) $invoice->amount_paid)->toBe(0);
});
