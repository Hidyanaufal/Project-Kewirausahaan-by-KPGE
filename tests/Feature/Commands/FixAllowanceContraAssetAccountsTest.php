<?php

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountDefaultKey;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryType;
use App\Models\AccountDefault;
use App\Models\Accounting\Account;
use App\Models\Accounting\JournalEntry;
use App\Services\AccountCreationService;
use App\Services\AccountService;
use App\Services\OpeningBalanceNameImportService;
use Illuminate\Support\Facades\Artisan;

function fixAllowanceCashReference(int $companyId): Account
{
    return Account::query()
        ->where('company_id', $companyId)
        ->where('type', AccountType::CurrentAsset)
        ->whereDoesntHave('children')
        ->firstOrFail();
}

function fixAllowanceConfigureOpeningEquity(Account $reference): Account
{
    $openingEquity = Account::factory()->create([
        'company_id' => $reference->company_id,
        'subtype_id' => $reference->subtype_id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '3999.fix.ob',
        'name' => 'Open Balance Equity Test Fix',
    ]);

    AccountDefault::query()->updateOrCreate(
        [
            'company_id' => $reference->company_id,
            'key' => AccountDefaultKey::OpenBalance->value,
        ],
        ['account_id' => $openingEquity->id]
    );

    $equitySubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::Equity)
        ->firstOrFail();

    Account::query()
        ->where('company_id', $reference->company_id)
        ->where(function ($query) {
            $query->whereRaw('LOWER(name) like ?', ['%retained earn%'])
                ->orWhereRaw('LOWER(name) like ?', ['%laba ditahan%']);
        })
        ->first() ?? Account::factory()->create([
            'company_id' => $reference->company_id,
            'subtype_id' => $equitySubtype->id,
            'category' => AccountCategory::Equity,
            'type' => AccountType::Equity,
            'code' => '3200.fix.re',
            'name' => 'Retained Earnings',
        ]);

    return $openingEquity;
}

it('fixes misclassified allowance account to contra asset and flips opening debit to credit', function () {
    $company = $this->testCompany;
    $reference = fixAllowanceCashReference($company->id);
    fixAllowanceConfigureOpeningEquity($reference);

    $arSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::CurrentAsset)
        ->firstOrFail();

    $allowance = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $arSubtype->id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::CurrentAsset,
        'code' => '1107.fix',
        'name' => 'Penyisihan Provisi Kerugian Piutang',
    ]);

    /** @var OpeningBalanceNameImportService $import */
    $import = app(OpeningBalanceNameImportService::class);
    $year = 2026;
    $desired = $import->parseBalanceToMinor('-652.395.427,84');

    $result = $import->postOpeningBalanceAdjustment($company->id, $allowance, $year, abs($desired));
    expect($result['posted'])->toBeTrue();

    $wrongEntry = JournalEntry::query()
        ->where('company_id', $company->id)
        ->where('account_id', $allowance->id)
        ->first();

    expect($wrongEntry)->not->toBeNull()
        ->and($wrongEntry->type)->toBe(JournalEntryType::Debit);

    $exit = Artisan::call('accounting:fix-allowance-contra-asset', [
        '--company-id' => $company->id,
        '--apply' => true,
    ]);

    expect($exit)->toBe(0);

    $allowance->refresh();
    expect($allowance->type)->toBe(AccountType::ContraAsset);

    $fixedEntry = JournalEntry::query()
        ->where('company_id', $company->id)
        ->where('account_id', $allowance->id)
        ->first();

    expect($fixedEntry->type)->toBe(JournalEntryType::Credit);

    $fyStart = $import->computeFiscalYearStartForYear($company, $year);
    /** @var AccountService $accountService */
    $accountService = app(AccountService::class);
    $starting = $accountService->getStartingBalance(
        $allowance,
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($starting?->getAmount())->toBe(abs($desired));
});

it('auto-corrects allowance type and flips debit during opening balance re-import', function () {
    $company = $this->testCompany;
    $reference = fixAllowanceCashReference($company->id);
    fixAllowanceConfigureOpeningEquity($reference);

    $equitySubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::Equity)
        ->firstOrFail();

    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $equitySubtype->id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '3200.ob.fix',
        'name' => 'RETAINED EARNING',
    ]);

    $arSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::CurrentAsset)
        ->firstOrFail();

    $allowance = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $arSubtype->id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::CurrentAsset,
        'code' => '1107.reimp',
        'name' => 'Penyisihan Provisi Kerugian Piutang',
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $year = 2026;
    $excel = $service->parseBalanceToMinor('-100.000,00');

    $service->postOpeningBalanceAdjustment($company->id, $allowance, $year, abs($excel));

    $mapping = [
        'version' => 1,
        'approved' => true,
        'approved_at' => now()->toIso8601String(),
        'company_id' => $company->id,
        'year' => $year,
        'rows' => [
            [
                'row_number' => 2,
                'excel_name' => $allowance->name,
                'balance_minor' => $excel,
                'status' => 'exact',
                'decision_status' => 'decided',
                'action' => 'import',
                'account_id' => $allowance->id,
                'rename_to_excel' => false,
            ],
        ],
    ];

    $service->importApprovedMapping($mapping, execute: true);

    $allowance->refresh();
    expect($allowance->type)->toBe(AccountType::ContraAsset);

    $entry = JournalEntry::query()
        ->where('company_id', $company->id)
        ->where('account_id', $allowance->id)
        ->where('type', JournalEntryType::Credit)
        ->first();

    expect($entry)->not->toBeNull();

    $fyStart = $service->computeFiscalYearStartForYear($company, $year);
    $starting = app(AccountService::class)->getStartingBalance(
        $allowance,
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($starting?->getAmount())->toBe(10000000);
});

it('ensureAllowanceContraAssetClassification is idempotent and skips beban', function () {
    $company = $this->testCompany;

    $expenseSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::OperatingExpense)
        ->firstOrFail();

    $beban = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $expenseSubtype->id,
        'category' => AccountCategory::Expense,
        'type' => AccountType::OperatingExpense,
        'code' => '5101.beban',
        'name' => 'Beban Penyisihan Piutang',
    ]);

    /** @var AccountCreationService $creation */
    $creation = app(AccountCreationService::class);

    expect($creation->ensureAllowanceContraAssetClassification($beban))->toBeFalse();
    expect($beban->fresh()->type)->toBe(AccountType::OperatingExpense);

    $arSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::CurrentAsset)
        ->firstOrFail();

    $allowance = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $arSubtype->id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::CurrentAsset,
        'code' => '1107.idem',
        'name' => 'Penyisihan Provisi Kerugian Piutang',
    ]);

    expect($creation->ensureAllowanceContraAssetClassification($allowance))->toBeTrue();
    expect($allowance->fresh()->type)->toBe(AccountType::ContraAsset);
    expect($creation->ensureAllowanceContraAssetClassification($allowance->fresh()))->toBeFalse();
});
