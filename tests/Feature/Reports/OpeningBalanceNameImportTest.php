<?php

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountDefaultKey;
use App\Enums\Accounting\AccountType;
use App\Models\AccountDefault;
use App\Models\Accounting\Account;
use App\Models\Accounting\Transaction;
use App\Services\AccountService;
use App\Services\OpeningBalanceNameImportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function obniConfigureOpeningEquity(Account $reference): Account
{
    $openingEquity = Account::factory()->create([
        'company_id' => $reference->company_id,
        'subtype_id' => $reference->subtype_id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '3999.ob',
        'name' => 'Open Balance Equity Test',
    ]);

    AccountDefault::query()->updateOrCreate(
        [
            'company_id' => $reference->company_id,
            'key' => AccountDefaultKey::OpenBalance->value,
        ],
        ['account_id' => $openingEquity->id]
    );

    return $openingEquity;
}

function obniMakeExcel(string $path, array $rows): void
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['Account No', 'Sub Account No', 'Account Name', 'Account Type', 'Balance'],
        ...$rows,
    ]);

    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    (new Xlsx($spreadsheet))->save($path);
}

function obniCashReference(int $companyId): Account
{
    return Account::query()
        ->where('company_id', $companyId)
        ->where('type', AccountType::CurrentAsset)
        ->whereDoesntHave('children')
        ->firstOrFail();
}

it('preview is read-only and builds draft mapping without decisions for fuzzy rows', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);

    $exact = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1900.01',
        'name' => 'Kas Uji Exact',
    ]);

    $fuzzyTarget = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1900.02',
        'name' => 'Piutang PT. KPP',
    ]);

    $excel = storage_path('framework/testing/obni-preview.xlsx');
    obniMakeExcel($excel, [
        ['', '1900.01', 'Kas Uji Exact', 'Cash/Bank', '1.000.000,00'],
        ['', '1900.02', 'Piutang PT KPPB', 'Account Receivable', '2.000.000,00'],
        ['', '1900.99', 'Akun Sama Sekali Baru', 'Cash/Bank', '500.000,00'],
        ['1000', '', 'Parent Skip', 'Cash/Bank', '9.000.000,00'],
    ]);

    $draft = storage_path('framework/testing/obni-preview.draft.json');
    $txBefore = Transaction::query()->where('company_id', $company->id)->count();
    $accountsBefore = Account::query()->where('company_id', $company->id)->count();

    $exit = Artisan::call('accounting:preview-opening-balance-name-import', [
        '--company-id' => $company->id,
        '--year' => 2026,
        '--excel' => $excel,
        '--export-mapping' => $draft,
    ]);

    expect($exit)->toBe(0)
        ->and(Transaction::query()->where('company_id', $company->id)->count())->toBe($txBefore)
        ->and(Account::query()->where('company_id', $company->id)->count())->toBe($accountsBefore);

    $mapping = json_decode((string) file_get_contents($draft), true);
    expect($mapping['approved'])->toBeFalse();

    $byName = collect($mapping['rows'])->keyBy('excel_name');

    expect($byName['Kas Uji Exact']['status'])->toBe('exact')
        ->and($byName['Kas Uji Exact']['decision_status'])->toBe('suggested')
        ->and($byName['Kas Uji Exact']['account_id'])->toBe($exact->id)
        ->and($byName['Kas Uji Exact']['rename_to_excel'])->toBeNull()
        ->and($byName['Piutang PT KPPB']['status'])->toBeIn(['fuzzy', 'ambiguous'])
        ->and($byName['Piutang PT KPPB']['decision_status'])->toBe('pending')
        ->and($byName['Piutang PT KPPB']['account_id'])->toBeNull()
        ->and($byName['Piutang PT KPPB']['rename_to_excel'])->toBeNull()
        ->and($byName['Piutang PT KPPB']['candidates'])->not->toBeEmpty()
        ->and($byName['Akun Sama Sekali Baru']['status'])->toBe('new')
        ->and($byName['Akun Sama Sekali Baru']['decision_status'])->toBe('pending')
        ->and($byName->has('Parent Skip'))->toBeFalse();

    expect($fuzzyTarget->fresh()->name)->toBe('Piutang PT. KPP');
});

it('rejects import when mapping is not approved', function () {
    $company = $this->testCompany;
    $draft = storage_path('framework/testing/obni-unapproved.json');
    file_put_contents($draft, json_encode([
        'version' => 1,
        'approved' => false,
        'company_id' => $company->id,
        'year' => 2026,
        'rows' => [
            [
                'row_number' => 2,
                'excel_name' => 'X',
                'balance_minor' => 100,
                'status' => 'exact',
                'decision_status' => 'suggested',
                'action' => 'import',
                'account_id' => 1,
                'rename_to_excel' => null,
            ],
        ],
    ]));

    $exit = Artisan::call('accounting:import-opening-balance-by-name', [
        '--company-id' => $company->id,
        '--year' => 2026,
        '--mapping' => $draft,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('belum approved');
});

it('imports from approved mapping with execute and sets FY starting balance', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);

    $account = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1901.01',
        'name' => 'Kas Cutover Lama',
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $desiredMinor = $service->parseBalanceToMinor('1.250.000,00');

    $year = 2026;
    $fyStart = $service->computeFiscalYearStartForYear($company, $year);

    $approved = storage_path('framework/testing/obni-approved.json');
    file_put_contents($approved, json_encode([
        'version' => 1,
        'approved' => true,
        'approved_at' => now()->toIso8601String(),
        'company_id' => $company->id,
        'year' => $year,
        'rows' => [
            [
                'row_number' => 2,
                'excel_name' => 'Kas Cutover Baru',
                'balance_minor' => $desiredMinor,
                'status' => 'fuzzy',
                'decision_status' => 'decided',
                'action' => 'map',
                'account_id' => $account->id,
                'rename_to_excel' => true,
            ],
            [
                'row_number' => 3,
                'excel_name' => 'Akun Baru OB',
                'balance_minor' => $service->parseBalanceToMinor('100.000,00'),
                'status' => 'new',
                'decision_status' => 'decided',
                'action' => 'create',
                'code' => '1901.88',
                'account_type' => 'Cash/Bank',
                'rename_to_excel' => false,
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $txBefore = Transaction::query()->where('company_id', $company->id)->count();

    $dryExit = Artisan::call('accounting:import-opening-balance-by-name', [
        '--company-id' => $company->id,
        '--year' => $year,
        '--mapping' => $approved,
    ]);

    expect($dryExit)->toBe(0)
        ->and(Transaction::query()->where('company_id', $company->id)->count())->toBe($txBefore)
        ->and($account->fresh()->name)->toBe('Kas Cutover Lama');

    $exit = Artisan::call('accounting:import-opening-balance-by-name', [
        '--company-id' => $company->id,
        '--year' => $year,
        '--mapping' => $approved,
        '--execute' => true,
    ]);

    expect($exit)->toBe(0)
        ->and($account->fresh()->name)->toBe('Kas Cutover Baru');

    $obTx = Transaction::query()
        ->where('company_id', $company->id)
        ->where('description', "Saldo Awal FY-{$year} (Import Adjust)")
        ->get();

    expect($obTx)->not->toBeEmpty();

    foreach ($obTx as $tx) {
        expect(Carbon::parse($tx->posted_at)->toDateString())
            ->toBe($fyStart->copy()->subSecond()->toDateString());
    }

    /** @var AccountService $accountService */
    $accountService = app(AccountService::class);
    $starting = $accountService->getStartingBalance(
        $account->fresh(),
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($starting?->getAmount())->toBe($desiredMinor);

    $created = Account::query()
        ->where('company_id', $company->id)
        ->where('code', '1901.88')
        ->first();

    expect($created)->not->toBeNull()
        ->and($created->name)->toBe('Akun Baru OB');

    $createdStarting = $accountService->getStartingBalance(
        $created,
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($createdStarting?->getAmount())->toBe($service->parseBalanceToMinor('100.000,00'));
});

it('matches exact unique names and skips parent and zero balance', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);

    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1910.01',
        'name' => 'Bank Uji Match',
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);

    $results = $service->match($company->id, [
        [
            'row_number' => 2,
            'account_no' => null,
            'sub_account_no' => '1910.01',
            'account_name' => 'Bank Uji Match',
            'account_type' => 'Cash/Bank',
            'balance_raw' => '10.000,00',
            'is_leaf' => true,
        ],
        [
            'row_number' => 3,
            'account_no' => '1910',
            'sub_account_no' => null,
            'account_name' => 'Parent Group',
            'account_type' => 'Cash/Bank',
            'balance_raw' => '10.000,00',
            'is_leaf' => false,
        ],
        [
            'row_number' => 4,
            'account_no' => null,
            'sub_account_no' => '1910.02',
            'account_name' => 'Zero Leaf',
            'account_type' => 'Cash/Bank',
            'balance_raw' => '0,00',
            'is_leaf' => true,
        ],
    ]);

    expect($results->firstWhere('rowNumber', 2)->status)->toBe('exact')
        ->and($results->firstWhere('rowNumber', 3)->status)->toBe('skipped_parent')
        ->and($results->firstWhere('rowNumber', 4)->status)->toBe('skipped_zero');
});

it('tracks excel parent from row order including duplicate parent codes', function () {
    $excel = storage_path('framework/testing/obni-parent-context.xlsx');
    obniMakeExcel($excel, [
        ['1100', '', 'Piutang Usaha', 'Account Receivable', '1.000,00'],
        ['', '1105', 'PT Max Project Indonesia', 'Account Receivable', '100.000,00'],
        ['1100', '', 'Penyisihan Piutang usaha', 'Account Receivable', '-50.000,00'],
        ['', '1107', 'Penyisihan Provisi Kerugian Piutang', 'Account Receivable', '-50.000,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $rows = collect($service->readExcelRows($excel))->keyBy('account_name');

    expect($rows['PT Max Project Indonesia']['excel_parent_name'])->toBe('Piutang Usaha')
        ->and($rows['PT Max Project Indonesia']['excel_parent_account_no'])->toBe('1100')
        ->and($rows['Penyisihan Provisi Kerugian Piutang']['excel_parent_name'])->toBe('Penyisihan Piutang usaha')
        ->and($rows['Penyisihan Provisi Kerugian Piutang']['excel_parent_account_no'])->toBe('1100');
});

it('suggests next child code under existing system parent for new accounts', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);

    $parent = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1600',
        'name' => 'Piutang Usaha',
        'parent_id' => null,
    ]);

    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1600.01',
        'name' => 'Piutang Existing 1',
        'parent_id' => $parent->id,
    ]);

    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1600.02',
        'name' => 'Piutang Existing 2',
        'parent_id' => $parent->id,
    ]);

    $excel = storage_path('framework/testing/obni-next-code.xlsx');
    obniMakeExcel($excel, [
        ['1100', '', 'Piutang Usaha', 'Account Receivable', '1.000,00'],
        ['', '1105', 'PT Max Project Indonesia', 'Account Receivable', '251.600.000,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $matches = $service->matchFromExcel($company->id, $excel);
    $new = $matches->firstWhere('excelName', 'PT Max Project Indonesia');

    expect($new)->not->toBeNull()
        ->and($new->status)->toBe('new')
        ->and($new->parentStatus)->toBe('found_existing')
        ->and($new->parentAction)->toBe('use_existing')
        ->and($new->parentAccountId)->toBe($parent->id)
        ->and($new->suggestedCode)->toBe('1600.03')
        ->and($new->excelCode)->toBe('1105')
        ->and($new->codeSource)->toBe('system_next');
});

it('creates parent then child on execute without posting OB to parent', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $desired = $service->parseBalanceToMinor('100.000,00');
    $year = 2026;

    $approved = storage_path('framework/testing/obni-create-parent.approved.json');
    file_put_contents($approved, json_encode([
        'version' => 1,
        'approved' => true,
        'approved_at' => now()->toIso8601String(),
        'company_id' => $company->id,
        'year' => $year,
        'rows' => [
            [
                'row_number' => 42,
                'excel_name' => 'Biaya Yang Masih Harus Dibayar',
                'excel_code' => '2300',
                'balance_minor' => 0,
                'status' => 'new_parent',
                'decision_status' => 'decided',
                'action' => 'create_parent',
                'code' => '2300',
                'account_type' => 'Other Current Liability',
                'rename_to_excel' => false,
                'parent_action' => 'create_parent',
            ],
            [
                'row_number' => 44,
                'excel_name' => 'Bonus Karyawan',
                'excel_code' => '2302',
                'balance_minor' => $desired,
                'status' => 'new',
                'decision_status' => 'decided',
                'action' => 'create',
                'code' => '2300.01',
                'account_type' => 'Other Current Liability',
                'rename_to_excel' => false,
                'parent_action' => 'create_parent',
                'excel_parent_account_no' => '2300',
                'excel_parent_name' => 'Biaya Yang Masih Harus Dibayar',
                'excel_parent_account_type' => 'Other Current Liability',
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $exit = Artisan::call('accounting:import-opening-balance-by-name', [
        '--company-id' => $company->id,
        '--year' => $year,
        '--mapping' => $approved,
        '--execute' => true,
    ]);

    expect($exit)->toBe(0);

    $parent = Account::query()->where('company_id', $company->id)->where('code', '2300')->first();
    $child = Account::query()->where('company_id', $company->id)->where('code', '2300.01')->first();

    expect($parent)->not->toBeNull()
        ->and($parent->name)->toBe('Biaya Yang Masih Harus Dibayar')
        ->and($child)->not->toBeNull()
        ->and($child->parent_id)->toBe($parent->id);

    $parentOb = Transaction::query()
        ->where('company_id', $company->id)
        ->where('description', "Saldo Awal FY-{$year} (Import Adjust)")
        ->whereHas('journalEntries', fn ($q) => $q->where('account_id', $parent->id))
        ->count();

    expect($parentOb)->toBe(0);

    $fyStart = $service->computeFiscalYearStartForYear($company, $year);
    $starting = app(AccountService::class)->getStartingBalance(
        $child,
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($starting?->getAmount())->toBe($desired);
});

it('marks new row as needs_create when excel parent missing in system', function () {
    $company = $this->testCompany;

    $excel = storage_path('framework/testing/obni-needs-create.xlsx');
    obniMakeExcel($excel, [
        ['8800', '', 'Parent Unik OB XYZ', 'Other Current Asset', '1.000,00'],
        ['', '8801', 'Child Unik OB XYZ', 'Other Current Asset', '50.000,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $new = $service->matchFromExcel($company->id, $excel)->firstWhere('excelName', 'Child Unik OB XYZ');

    expect($new)->not->toBeNull()
        ->and($new->status)->toBe('new')
        ->and($new->parentStatus)->toBe('needs_create')
        ->and($new->parentAction)->toBe('create_parent')
        ->and($new->suggestedCode)->toBe('8800.01')
        ->and($new->excelCode)->toBe('8801');
});

it('auto-skips Current Earning of The Year through preview mapping and execute', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);

    $retained = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '3005',
        'name' => 'RETAINED EARNING',
    ]);

    $excel = storage_path('framework/testing/obni-skip-ltj.xlsx');
    obniMakeExcel($excel, [
        ['3000', '', 'Equity Parent', 'Equity', '5.000.000,00'],
        ['', '3004', 'Current Earning of The Year', 'Equity', '3.471.039.794,61'],
        ['', '3005', 'RETAINED EARNING', 'Equity', '1.000.000,00'],
    ]);

    $draft = storage_path('framework/testing/obni-skip-ltj.draft.json');
    $exit = Artisan::call('accounting:preview-opening-balance-name-import', [
        '--company-id' => $company->id,
        '--year' => 2026,
        '--excel' => $excel,
        '--export-mapping' => $draft,
    ]);
    $previewOutput = Artisan::output();

    expect($exit)->toBe(0)
        ->and($previewOutput)->toContain('Current Earning of The Year')
        ->and($previewOutput)->toContain('Tidak perlu di-import');

    $mapping = json_decode((string) file_get_contents($draft), true);
    $byName = collect($mapping['rows'])->keyBy('excel_name');

    expect($byName->has('Current Earning of The Year'))->toBeTrue()
        ->and($byName['Current Earning of The Year']['status'])->toBe('skipped_computed_equity')
        ->and($byName['Current Earning of The Year']['decision_status'])->toBe('skipped')
        ->and($byName['Current Earning of The Year']['action'])->toBe('skip')
        ->and($byName['Current Earning of The Year']['skip_reason'])
        ->toBe(OpeningBalanceNameImportService::SKIP_REASON_COMPUTED_CURRENT_YEAR_EARNINGS)
        ->and($byName['RETAINED EARNING']['status'])->toBe('exact')
        ->and($byName->has('Equity Parent'))->toBeFalse();

    $approved = storage_path('framework/testing/obni-skip-ltj.approved.json');
    file_put_contents($approved, json_encode([
        'version' => 1,
        'approved' => true,
        'approved_at' => now()->toIso8601String(),
        'company_id' => $company->id,
        'year' => 2026,
        'rows' => [
            $byName['Current Earning of The Year'],
            [
                ...$byName['RETAINED EARNING'],
                'decision_status' => 'decided',
                'action' => 'import',
                'rename_to_excel' => false,
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $txBefore = Transaction::query()->where('company_id', $company->id)->count();

    $importExit = Artisan::call('accounting:import-opening-balance-by-name', [
        '--company-id' => $company->id,
        '--year' => 2026,
        '--mapping' => $approved,
        '--execute' => true,
    ]);
    $importOutput = Artisan::output();

    expect($importExit)->toBe(0)
        ->and($importOutput)->toContain('Tidak perlu di-import')
        ->and(Transaction::query()->where('company_id', $company->id)->count())->toBeGreaterThan($txBefore);

    $ltjPosted = Transaction::query()
        ->where('company_id', $company->id)
        ->where('description', 'Saldo Awal FY-2026 (Import Adjust)')
        ->whereHas('journalEntries', function ($q) {
            $q->whereHas('account', fn ($a) => $a->where('name', 'Current Earning of The Year'));
        })
        ->count();

    expect($ltjPosted)->toBe(0);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $fyStart = $service->computeFiscalYearStartForYear($company, 2026);
    $starting = app(AccountService::class)->getStartingBalance(
        $retained->fresh(),
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($starting?->getAmount())->toBe($service->parseBalanceToMinor('1.000.000,00'));
});

it('skips revenue and expense rows from opening balance name import', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);

    $revenueSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::OperatingRevenue)
        ->firstOrFail();

    $revenue = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $revenueSubtype->id,
        'category' => AccountCategory::Revenue,
        'type' => AccountType::OperatingRevenue,
        'code' => '4900.01',
        'name' => 'Pendapatan Uji OB',
    ]);

    expect($revenue->fresh()->category)->toBe(AccountCategory::Revenue);

    $excel = storage_path('framework/testing/obni-skip-pnl.xlsx');
    obniMakeExcel($excel, [
        ['', '1000.01', 'Kas Leaf OK', 'Cash/Bank', '1.000.000,00'],
        ['', '4900.01', 'Pendapatan Uji OB', 'Revenue', '5.000.000,00'],
        ['', '5900.01', 'Biaya Baru OB', 'Expense', '2.000.000,00'],
    ]);

    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1000.01',
        'name' => 'Kas Leaf OK',
    ]);

    $draft = storage_path('framework/testing/obni-skip-pnl.draft.json');
    $exit = Artisan::call('accounting:preview-opening-balance-name-import', [
        '--company-id' => $company->id,
        '--year' => 2026,
        '--excel' => $excel,
        '--export-mapping' => $draft,
    ]);

    expect($exit)->toBe(0);

    $mapping = json_decode((string) file_get_contents($draft), true);
    $byName = collect($mapping['rows'])->keyBy('excel_name');

    expect($byName['Pendapatan Uji OB']['action'])->toBe('skip')
        ->and($byName['Pendapatan Uji OB']['skip_reason'])
        ->toBe(OpeningBalanceNameImportService::SKIP_REASON_NOMINAL_PNL)
        ->and($byName['Biaya Baru OB']['action'])->toBe('skip')
        ->and($byName['Kas Leaf OK']['action'])->toBe('import');

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $result = $service->postOpeningBalanceAdjustment($company->id, $revenue->fresh(), 2026, 500_000_00);

    expect($result['posted'])->toBeFalse()
        ->and($result['transaction_id'])->toBeNull();
});

it('auto-approves exact map and create rows and imports via shared service method', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);
    $year = 2026;

    $exact = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1911.01',
        'name' => 'Kas Auto Exact',
    ]);

    $excel = storage_path('framework/testing/obni-auto.xlsx');
    obniMakeExcel($excel, [
        ['', '1911.01', 'Kas Auto Exact', 'Cash/Bank', '250.000,00'],
        ['', '1911.99', 'Kas Auto Baru Unik', 'Cash/Bank', '75.000,00'],
        ['', '4000.x', 'Pendapatan Auto Skip', 'Revenue', '9.000.000,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $matches = $service->matchFromExcel($company->id, $excel);
    $preview = $service->buildPreviewTable($matches);

    expect($preview['summary']['exact'])->toBeGreaterThanOrEqual(1)
        ->and($preview['summary']['create'])->toBeGreaterThanOrEqual(1)
        ->and($preview['summary']['skip'])->toBeGreaterThanOrEqual(1);

    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);

    expect($mapping['approved'])->toBeTrue();

    $byName = collect($mapping['rows'])->keyBy('excel_name');
    expect($byName['Kas Auto Exact']['action'])->toBe('import')
        ->and($byName['Kas Auto Exact']['decision_status'])->toBe('decided')
        ->and($byName['Kas Auto Baru Unik']['action'])->toBe('create')
        ->and($byName['Pendapatan Auto Skip']['action'])->toBe('skip');

    $dry = $service->importApprovedMapping($mapping, execute: false);
    expect($dry)->not->toBeEmpty();

    $live = $service->importApprovedMapping($mapping, execute: true);
    $actions = collect($live)->pluck(2);

    expect($actions)->toContain('import')
        ->and($actions)->toContain('create');

    expect(Account::query()->where('company_id', $company->id)->where('name', 'Kas Auto Baru Unik')->exists())->toBeTrue();

    $fyStart = $service->computeFiscalYearStartForYear($company, $year);
    $starting = app(AccountService::class)->getStartingBalance(
        $exact->fresh(),
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($starting?->getAmount())->toBe($service->parseBalanceToMinor('250.000,00'));
});
