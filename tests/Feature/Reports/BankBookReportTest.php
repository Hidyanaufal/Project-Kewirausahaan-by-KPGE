<?php

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryType;
use App\Filament\Company\Pages\Reports\AccountTransactions;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Models\Accounting\Transaction;
use App\Models\Company;

use function Pest\Livewire\livewire;

/**
 * @return array{
 *     parent: Account,
 *     kasKecil: Account,
 *     kasBesar: Account,
 *     bankMandiri: Account,
 *     offset: Account
 * }
 */
function setupKasBankChart(Company $company, int $userId): array
{
    $subtype = AccountSubtype::query()
        ->where('name', 'Cash and Cash Equivalents')
        ->firstOrFail();

    $parent = Account::query()->create([
        'company_id' => $company->id,
        'parent_id' => null,
        'code' => 'KB1000',
        'name' => 'Kas & Bank',
        'category' => AccountCategory::Asset,
        'type' => AccountType::CurrentAsset,
        'subtype_id' => $subtype->id,
        'currency_code' => 'IDR',
        'created_by' => $userId,
        'updated_by' => $userId,
    ]);

    $childDefaults = [
        'company_id' => $company->id,
        'parent_id' => $parent->id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::CurrentAsset,
        'subtype_id' => $subtype->id,
        'currency_code' => 'IDR',
        'created_by' => $userId,
        'updated_by' => $userId,
    ];

    $kasKecil = Account::query()->create(array_merge($childDefaults, [
        'code' => 'KB1000.01',
        'name' => 'Kas kecil',
    ]));

    $kasBesar = Account::query()->create(array_merge($childDefaults, [
        'code' => 'KB1000.02',
        'name' => 'Kas Besar',
    ]));

    $bankMandiri = Account::query()->create(array_merge($childDefaults, [
        'code' => 'KB1000.03',
        'name' => 'Bank Mandiri',
    ]));

    $offset = Account::query()
        ->where('company_id', $company->id)
        ->where('category', AccountCategory::Revenue)
        ->whereDoesntHave('children')
        ->firstOrFail();

    return compact('parent', 'kasKecil', 'kasBesar', 'bankMandiri', 'offset');
}

function createCashJournal(
    Company $company,
    Account $cashAccount,
    Account $offsetAccount,
    int $amount,
    string $postedAt,
    int $userId,
    bool $isDeposit = true,
): Transaction {
    $transaction = Transaction::factory()
        ->state([
            'company_id' => $company->id,
        ])
        ->asJournal($amount)
        ->create([
            'posted_at' => $postedAt,
        ]);

    $entryDefaults = [
        'company_id' => $company->id,
        'created_by' => $userId,
        'updated_by' => $userId,
    ];

    if ($isDeposit) {
        $transaction->journalEntries()->create(array_merge($entryDefaults, [
            'account_id' => $cashAccount->id,
            'type' => JournalEntryType::Debit,
            'amount' => $amount,
        ]));
        $transaction->journalEntries()->create(array_merge($entryDefaults, [
            'account_id' => $offsetAccount->id,
            'type' => JournalEntryType::Credit,
            'amount' => $amount,
        ]));
    } else {
        $transaction->journalEntries()->create(array_merge($entryDefaults, [
            'account_id' => $offsetAccount->id,
            'type' => JournalEntryType::Debit,
            'amount' => $amount,
        ]));
        $transaction->journalEntries()->create(array_merge($entryDefaults, [
            'account_id' => $cashAccount->id,
            'type' => JournalEntryType::Credit,
            'amount' => $amount,
        ]));
    }

    return $transaction;
}

function buildBankBookReport(array $filterOverride): array
{
    return livewire(AccountTransactions::class)
        ->instance()
        ->__buildReportBankBook($filterOverride);
}

function findSaldoAwalRows(array $rows): array
{
    return array_values(array_filter(
        $rows,
        fn (array $row) => ($row['data'][2] ?? '') === 'Saldo Awal',
    ));
}

function parseIdrMinor(string $formatted): int
{
    $clean = str_replace('.', '', $formatted);
    $clean = str_replace(',', '.', $clean);

    return (int) round(((float) $clean) * 100);
}

it('shows aggregate saldo awal as sum of all Kas & Bank children before start date', function () {
    $company = $this->testCompany;
    $accounts = setupKasBankChart($company, $this->testUser->id);

    createCashJournal($company, $accounts['kasKecil'], $accounts['offset'], 1_000_000, '2025-12-20', $this->testUser->id);
    createCashJournal($company, $accounts['kasBesar'], $accounts['offset'], 2_000_000, '2025-12-25', $this->testUser->id);
    createCashJournal($company, $accounts['bankMandiri'], $accounts['offset'], 3_000_000, '2025-12-31', $this->testUser->id);

    createCashJournal($company, $accounts['bankMandiri'], $accounts['offset'], 500_000, '2026-01-05', $this->testUser->id);

    $report = buildBankBookReport([
        'startDate' => '2026-01-01',
        'endDate' => '2026-01-31',
        'selectedAccount' => 'all',
        'selectedPayee' => 'all',
    ]);

    $saldoAwalRows = findSaldoAwalRows($report['rows']);

    expect($saldoAwalRows)->toHaveCount(1)
        ->and(parseIdrMinor($saldoAwalRows[0]['data'][5]))->toBe(6_000_000);
});

it('shows per-account saldo awal when a specific Kas & Bank child is filtered', function () {
    $company = $this->testCompany;
    $accounts = setupKasBankChart($company, $this->testUser->id);

    createCashJournal($company, $accounts['kasKecil'], $accounts['offset'], 1_000_000, '2025-12-20', $this->testUser->id);
    createCashJournal($company, $accounts['kasBesar'], $accounts['offset'], 2_000_000, '2025-12-25', $this->testUser->id);
    createCashJournal($company, $accounts['bankMandiri'], $accounts['offset'], 3_000_000, '2025-12-31', $this->testUser->id);

    $report = buildBankBookReport([
        'startDate' => '2026-01-01',
        'endDate' => '2026-01-31',
        'selectedAccount' => (string) $accounts['bankMandiri']->id,
        'selectedPayee' => 'all',
    ]);

    $saldoAwalRows = findSaldoAwalRows($report['rows']);

    expect($saldoAwalRows)->toHaveCount(1)
        ->and(parseIdrMinor($saldoAwalRows[0]['data'][5]))->toBe(3_000_000);
});

it('uses H-1 logic so opening balance reflects journals before start date only', function () {
    $company = $this->testCompany;
    $accounts = setupKasBankChart($company, $this->testUser->id);

    createCashJournal($company, $accounts['bankMandiri'], $accounts['offset'], 1_000_000, '2026-05-14', $this->testUser->id);
    createCashJournal($company, $accounts['bankMandiri'], $accounts['offset'], 2_000_000, '2026-05-15', $this->testUser->id);

    $report = buildBankBookReport([
        'startDate' => '2026-05-15',
        'endDate' => '2026-05-31',
        'selectedAccount' => (string) $accounts['bankMandiri']->id,
        'selectedPayee' => 'all',
    ]);

    $saldoAwalRows = findSaldoAwalRows($report['rows']);

    expect($saldoAwalRows)->toHaveCount(1)
        ->and(parseIdrMinor($saldoAwalRows[0]['data'][5]))->toBe(1_000_000);
});

it('calculates running balance from saldo awal plus period transactions', function () {
    $company = $this->testCompany;
    $accounts = setupKasBankChart($company, $this->testUser->id);

    createCashJournal($company, $accounts['bankMandiri'], $accounts['offset'], 1_000_000, '2025-12-31', $this->testUser->id);
    createCashJournal($company, $accounts['bankMandiri'], $accounts['offset'], 250_000, '2026-01-10', $this->testUser->id);

    $report = buildBankBookReport([
        'startDate' => '2026-01-01',
        'endDate' => '2026-01-31',
        'selectedAccount' => (string) $accounts['bankMandiri']->id,
        'selectedPayee' => 'all',
    ]);

    $transactionRows = collect($report['rows'])
        ->filter(fn (array $row) => ($row['data'][2] ?? '') !== 'Saldo Awal'
            && ($row['data'][2] ?? '') !== ''
            && ! str_starts_with((string) ($row['data'][0] ?? ''), '__str'))
        ->values();

    expect($transactionRows)->not->toBeEmpty()
        ->and(parseIdrMinor($transactionRows[0]['data'][5]))->toBe(1_250_000);
});

it('includes Kas kecil transactions when exporting all Kas & Bank accounts', function () {
    $company = $this->testCompany;
    $accounts = setupKasBankChart($company, $this->testUser->id);

    createCashJournal($company, $accounts['kasKecil'], $accounts['offset'], 750_000, '2026-01-08', $this->testUser->id);

    $report = buildBankBookReport([
        'startDate' => '2026-01-01',
        'endDate' => '2026-01-31',
        'selectedAccount' => 'all',
        'selectedPayee' => 'all',
    ]);

    $descriptions = collect($report['rows'])
        ->pluck('data')
        ->map(fn (array $data) => $data[2] ?? '')
        ->filter()
        ->values()
        ->all();

    expect($descriptions)->toContain('Saldo Awal');

    $kasKecilHeader = collect($report['rows'])
        ->first(fn (array $row) => ($row['data'][1] ?? '') === 'Kas kecil');

    expect($kasKecilHeader)->not->toBeNull();
});
