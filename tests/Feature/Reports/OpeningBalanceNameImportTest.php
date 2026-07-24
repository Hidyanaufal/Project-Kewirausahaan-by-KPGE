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
            'code' => '3200.re',
            'name' => 'Retained Earnings',
        ]);

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

it('imports revenue and expense rows from opening balance name import', function () {
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

    $excel = storage_path('framework/testing/obni-import-pnl.xlsx');
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

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $matches = $service->matchFromExcel($company->id, $excel);
    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, 2026, $excel, $matches);
    $byName = collect($mapping['rows'])->keyBy('excel_name');

    expect($byName['Pendapatan Uji OB']['action'])->toBe('import')
        ->and($byName['Biaya Baru OB']['action'])->toBe('create')
        ->and($byName['Kas Leaf OK']['action'])->toBe('import');

    $revenueMinor = $service->parseBalanceToMinor('5.000.000,00');
    $expenseMinor = $service->parseBalanceToMinor('2.000.000,00');

    $live = $service->importApprovedMapping($mapping, execute: true);
    $actions = collect($live)->pluck(2);

    expect($actions)->toContain('import')
        ->and($actions)->toContain('create');

    $fyStart = $service->computeFiscalYearStartForYear($company, 2026);
    $accountService = app(AccountService::class);

    $revenueStarting = $accountService->getStartingBalance(
        $revenue->fresh(),
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    $expense = Account::query()
        ->where('company_id', $company->id)
        ->where('name', 'Biaya Baru OB')
        ->first();

    expect($expense)->not->toBeNull()
        ->and($expense->type)->toBe(AccountType::OperatingExpense)
        ->and($expense->subtype_id)->not->toBeNull();

    $expenseStarting = $accountService->getStartingBalance(
        $expense->fresh(),
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($revenueStarting?->getAmount())->toBe($revenueMinor)
        ->and($expenseStarting?->getAmount())->toBe($expenseMinor);

    $revenueEntry = \App\Models\Accounting\JournalEntry::query()
        ->where('company_id', $company->id)
        ->where('account_id', $revenue->id)
        ->where('description', "Saldo Awal FY-2026 - {$revenue->code}")
        ->first();

    expect($revenueEntry)->not->toBeNull()
        ->and($revenueEntry->type)->toBe(\App\Enums\Accounting\JournalEntryType::Credit)
        ->and((int) $revenueEntry->amount)->toBe($revenueMinor);
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
        ['', '4000.x', 'Pendapatan Auto Import', 'Revenue', '9.000.000,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $matches = $service->matchFromExcel($company->id, $excel);
    $preview = $service->buildPreviewTable($matches);

    expect($preview['summary']['exact'])->toBeGreaterThanOrEqual(1)
        ->and($preview['summary']['create'])->toBeGreaterThanOrEqual(2);

    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);

    expect($mapping['approved'])->toBeTrue();

    $byName = collect($mapping['rows'])->keyBy('excel_name');
    expect($byName['Kas Auto Exact']['action'])->toBe('import')
        ->and($byName['Kas Auto Exact']['decision_status'])->toBe('decided')
        ->and($byName['Kas Auto Baru Unik']['action'])->toBe('create')
        ->and($byName['Pendapatan Auto Import']['action'])->toBe('create');

    $dry = $service->importApprovedMapping($mapping, execute: false);
    expect($dry)->not->toBeEmpty();

    $live = $service->importApprovedMapping($mapping, execute: true);
    $actions = collect($live)->pluck(2);

    expect($actions)->toContain('import')
        ->and($actions)->toContain('create');

    expect(Account::query()->where('company_id', $company->id)->where('name', 'Kas Auto Baru Unik')->exists())->toBeTrue();
    expect(Account::query()->where('company_id', $company->id)->where('name', 'Pendapatan Auto Import')->exists())->toBeTrue();

    $fyStart = $service->computeFiscalYearStartForYear($company, $year);
    $starting = app(AccountService::class)->getStartingBalance(
        $exact->fresh(),
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($starting?->getAmount())->toBe($service->parseBalanceToMinor('250.000,00'));

    $revenue = Account::query()
        ->where('company_id', $company->id)
        ->where('name', 'Pendapatan Auto Import')
        ->firstOrFail();

    $revenueStarting = app(AccountService::class)->getStartingBalance(
        $revenue,
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($revenueStarting?->getAmount())->toBe($service->parseBalanceToMinor('9.000.000,00'));
});

it('zeroes balance-sheet and pnl accounts absent from excel while keeping excel targets', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    $openingEquity = obniConfigureOpeningEquity($reference);
    $year = 2026;

    $inExcel = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1920.01',
        'name' => 'Bank Mandiri Uji Zero',
    ]);

    $legacy = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1920.02',
        'name' => 'Account Receivable IDR Uji',
    ]);

    $revenueSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::OperatingRevenue)
        ->firstOrFail();

    $revenueLegacy = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $revenueSubtype->id,
        'category' => AccountCategory::Revenue,
        'type' => AccountType::OperatingRevenue,
        'code' => '4920.01',
        'name' => 'Pendapatan Legacy Di-nol',
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $legacyMinor = $service->parseBalanceToMinor('18.900.000,00');
    $revenueMinor = $service->parseBalanceToMinor('3.000.000,00');
    $excelMinor = $service->parseBalanceToMinor('416.743,46');

    expect($service->postOpeningBalanceAdjustment($company->id, $legacy, $year, $legacyMinor)['posted'])->toBeTrue();

    $fyStart = $service->computeFiscalYearStartForYear($company, $year);
    $postedAt = $fyStart->copy()->subDay()->toDateTimeString();
    $tx = Transaction::query()->create([
        'company_id' => $company->id,
        'type' => 'journal',
        'description' => 'Legacy revenue seed',
        'posted_at' => $postedAt,
        'amount' => $revenueMinor,
        'reviewed' => false,
    ]);
    \App\Models\Accounting\JournalEntry::query()->create([
        'company_id' => $company->id,
        'transaction_id' => $tx->id,
        'account_id' => $revenueLegacy->id,
        'type' => \App\Enums\Accounting\JournalEntryType::Credit,
        'amount' => $revenueMinor,
        'description' => 'Legacy revenue seed',
    ]);
    \App\Models\Accounting\JournalEntry::query()->create([
        'company_id' => $company->id,
        'transaction_id' => $tx->id,
        'account_id' => $openingEquity->id,
        'type' => \App\Enums\Accounting\JournalEntryType::Debit,
        'amount' => $revenueMinor,
        'description' => 'Legacy revenue seed counter',
    ]);

    $excel = storage_path('framework/testing/obni-zero-missing.xlsx');
    obniMakeExcel($excel, [
        ['', '1920.01', 'Bank Mandiri Uji Zero', 'Cash/Bank', '416.743,46'],
    ]);

    $matches = $service->matchFromExcel($company->id, $excel);
    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);

    $dry = $service->importApprovedMapping($mapping, execute: false);
    $dryZeros = collect($dry)->where(fn ($r) => ($r[2] ?? '') === OpeningBalanceNameImportService::ACTION_ZERO_MISSING);

    expect($dryZeros->pluck(1))->toContain('Account Receivable IDR Uji')
        ->and($dryZeros->pluck(1))->toContain('Pendapatan Legacy Di-nol')
        ->and($dryZeros->pluck(1))->not->toContain('Bank Mandiri Uji Zero');

    $live = $service->importApprovedMapping($mapping, execute: true);
    expect(collect($live)->pluck(2))->toContain(OpeningBalanceNameImportService::ACTION_ZERO_MISSING)
        ->and(collect($live)->pluck(2))->toContain('import');

    $accountService = app(AccountService::class);

    $legacyStarting = $accountService->getStartingBalance(
        $legacy->fresh(),
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );
    $inExcelStarting = $accountService->getStartingBalance(
        $inExcel->fresh(),
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );
    $revenueStarting = $accountService->getStartingBalance(
        $revenueLegacy->fresh(),
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($legacyStarting?->getAmount())->toBe(0)
        ->and($inExcelStarting?->getAmount())->toBe($excelMinor)
        ->and($revenueStarting?->getAmount())->toBe(0);
});

it('zeroes control parent accounts with own balance when absent from excel', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);
    $year = 2026;

    $control = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1930',
        'name' => 'Account Receivable Control Uji',
    ]);

    $child = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1930-001',
        'name' => 'Piutang Anak Control Uji',
    ]);

    expect($control->fresh()->isParentAccount())->toBeTrue();

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $controlMinor = $service->parseBalanceToMinor('9.037.597.643,71');
    $childMinor = $service->parseBalanceToMinor('83.459.644,00');
    $excelChildMinor = $service->parseBalanceToMinor('83.459.644,00');

    expect($service->postOpeningBalanceAdjustment($company->id, $control, $year, $controlMinor)['posted'])->toBeTrue()
        ->and($service->postOpeningBalanceAdjustment($company->id, $child, $year, $childMinor)['posted'])->toBeTrue();

    $excel = storage_path('framework/testing/obni-zero-parent-control.xlsx');
    obniMakeExcel($excel, [
        ['', '1930-001', 'Piutang Anak Control Uji', 'Cash/Bank', '83.459.644,00'],
    ]);

    $matches = $service->matchFromExcel($company->id, $excel);
    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);

    $dry = $service->importApprovedMapping($mapping, execute: false);
    $dryZeros = collect($dry)->where(fn ($r) => ($r[2] ?? '') === OpeningBalanceNameImportService::ACTION_ZERO_MISSING);

    expect($dryZeros->pluck(1))->toContain('Account Receivable Control Uji')
        ->and($dryZeros->pluck(1))->not->toContain('Piutang Anak Control Uji');

    $service->importApprovedMapping($mapping, execute: true);

    $accountService = app(AccountService::class);
    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();

    $controlStarting = $accountService->getStartingBalance(
        $control->fresh(),
        $fyStart,
        override: true,
        projectId: null,
        companyId: $company->id,
    );
    $childStarting = $accountService->getStartingBalance(
        $child->fresh(),
        $fyStart,
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($controlStarting?->getAmount())->toBe(0)
        ->and($childStarting?->getAmount())->toBe($excelChildMinor);
});

it('does not overwrite asset opening balance with fuzzy zero AP row mapped to same account', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);
    $year = 2026;

    $investment = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1950.01',
        'name' => 'PT Ksatria Pratama Persada Energi',
    ]);

    $excel = storage_path('framework/testing/obni-ksatria-conflict.xlsx');
    obniMakeExcel($excel, [
        ['1200', '', 'Investasi pada anak perusahaan', 'Other Current Asset', '650.000,00'],
        ['', '1205', 'PT Ksatria Pratama Persada Energi', 'Other Current Asset', '650.000,00'],
        ['2000', '', 'Utang Usaha', 'Account Payable', '0,00'],
        ['', '2002', 'Ksatria Pratama Persada Energi', 'Account Payable', '0,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $desired = $service->parseBalanceToMinor('650.000,00');

    $matches = $service->matchFromExcel($company->id, $excel);
    $byName = $matches->keyBy(fn ($m) => $m->excelName);

    expect($byName['PT Ksatria Pratama Persada Energi']->status)->toBe('exact')
        ->and($byName['PT Ksatria Pratama Persada Energi']->action)->toBe('import')
        ->and($byName['Ksatria Pratama Persada Energi']->decisionStatus)->toBe('skipped')
        ->and($byName['Ksatria Pratama Persada Energi']->action)->toBe('skip');

    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);
    $mapped = collect($mapping['rows'])->keyBy('excel_name');

    expect($mapped['PT Ksatria Pratama Persada Energi']['action'])->toBe('import')
        ->and($mapped['Ksatria Pratama Persada Energi']['action'])->toBe('skip');

    $service->importApprovedMapping($mapping, execute: true);

    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
    $starting = app(AccountService::class)->getStartingBalance(
        $investment->fresh(),
        $fyStart,
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($starting?->getAmount())->toBe($desired);
});

it('disambiguates duplicate Peralatan Kantor names by excel account type', function () {
    $company = $this->testCompany;
    $year = 2026;

    $fixedSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::NonCurrentAsset)
        ->firstOrFail();
    $contraSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::ContraAsset)
        ->firstOrFail();

    $assetParent = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $fixedSubtype->id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::NonCurrentAsset,
        'code' => '1960',
        'name' => 'Aset Tetap',
    ]);
    $accumParent = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $contraSubtype->id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::ContraAsset,
        'code' => '1961',
        'name' => 'Akumulasi Penyusutan',
    ]);

    $asset = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $fixedSubtype->id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::NonCurrentAsset,
        'parent_id' => $assetParent->id,
        'code' => '1960.01',
        'name' => 'Peralatan Kantor',
    ]);
    $accum = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $contraSubtype->id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::ContraAsset,
        'parent_id' => $accumParent->id,
        'code' => '1961.01',
        'name' => 'Peralatan Kantor',
    ]);

    obniConfigureOpeningEquity(obniCashReference($company->id));

    $excel = storage_path('framework/testing/obni-peralatan-ambiguous.xlsx');
    obniMakeExcel($excel, [
        ['1960', '', 'Aset Tetap', 'Fixed Asset', '19.270.000,00'],
        ['', '1960.01', 'Peralatan Kantor', 'Fixed Asset', '19.270.000,00'],
        ['1961', '', 'Akumulasi Penyusutan', 'Accumulated Depreciation', '-19.270.000,00'],
        ['', '1961.01', 'Peralatan Kantor', 'Accumulated Depreciation', '-19.270.000,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $desired = $service->parseBalanceToMinor('19.270.000,00');

    $matches = $service->matchFromExcel($company->id, $excel);
    $peralatan = $matches->where('excelName', 'Peralatan Kantor')->values();

    expect($peralatan)->toHaveCount(2)
        ->and($peralatan[0]->status)->toBe('exact')
        ->and($peralatan[0]->accountId)->toBe($asset->id)
        ->and($peralatan[0]->excelAccountType)->toBe('Fixed Asset')
        ->and($peralatan[1]->status)->toBe('exact')
        ->and($peralatan[1]->accountId)->toBe($accum->id)
        ->and($peralatan[1]->excelAccountType)->toBe('Accumulated Depreciation');

    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);
    $service->importApprovedMapping($mapping, execute: true);

    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
    $accountService = app(AccountService::class);

    $assetStart = $accountService->getStartingBalance(
        $asset->fresh(),
        $fyStart,
        override: true,
        projectId: null,
        companyId: $company->id,
    );
    $accumStart = $accountService->getStartingBalance(
        $accum->fresh(),
        $fyStart,
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($assetStart?->getAmount())->toBe($desired)
        ->and($accumStart?->getAmount())->toBe($desired);
});

it('does not fuzzy-map Utang PPh 29 onto Hutang PPh 23 when digits differ', function () {
    $company = $this->testCompany;
    $year = 2026;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);

    $liabilitySubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::CurrentLiability)
        ->firstOrFail();

    $wrong = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $liabilitySubtype->id,
        'category' => AccountCategory::Liability,
        'type' => AccountType::CurrentLiability,
        'code' => '2903.x',
        'name' => 'Hutang PPh 23',
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $desired = $service->parseBalanceToMinor('275.231.920,07');

    expect($service->fuzzyNumberTokensCompatible('Utang PPh 29', 'Hutang PPh 23'))->toBeFalse()
        ->and($service->fuzzyNumberTokensCompatible('Utang PPh 29', 'Hutang PPh 29'))->toBeTrue()
        ->and($service->fuzzyNumberTokensCompatible('PPh 21', 'Hutang Pajak PPh 21'))->toBeTrue();

    $excel = storage_path('framework/testing/obni-pph29.xlsx');
    obniMakeExcel($excel, [
        ['2100', '', 'Utang Pajak', 'Other Current Liability', '275.231.920,07'],
        ['', '2103', 'Utang PPh 29', 'Other Current Liability', '275.231.920,07'],
    ]);

    $matches = $service->matchFromExcel($company->id, $excel);
    $row = $matches->first(fn ($m) => $m->excelName === 'Utang PPh 29');

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('new')
        ->and($row->accountId)->toBeNull()
        ->and($row->candidates)->toBeEmpty();

    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);
    $mapped = collect($mapping['rows'])->firstWhere('excel_name', 'Utang PPh 29');
    expect($mapped['action'])->toBe('create');

    $service->importApprovedMapping($mapping, execute: true);

    $created = Account::query()
        ->where('company_id', $company->id)
        ->where('name', 'Utang PPh 29')
        ->first();

    expect($created)->not->toBeNull();

    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
    $accountService = app(AccountService::class);

    expect($accountService->getStartingBalance(
        $created,
        $fyStart,
        override: true,
        projectId: null,
        companyId: $company->id,
    )?->getAmount())->toBe($desired)
        ->and($accountService->getStartingBalance(
            $wrong->fresh(),
            $fyStart,
            override: true,
            projectId: null,
            companyId: $company->id,
        )?->getAmount())->toBe(0);
});

it('parses KPG negative balances with minus prefix and parentheses as signed minor', function () {
    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);

    expect($service->parseBalanceToMinor('-652.395.427,84'))->toBe(-65239542784)
        ->and($service->parseBalanceToMinor('(31.454.166,34)'))->toBe(-3145416634)
        ->and($service->parseBalanceToMinor('−1.000,00'))->toBe(-100000);
});

it('converts excel signed balance to absolute desiredMinor; D/C follows account type', function () {
    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);

    $excelNegative = -3145416634;

    // Minus diabaikan — desired selalu magnitude positif; sisi D/C dari tipe akun.
    expect($service->excelSignedToDesiredMinor(AccountType::CurrentAsset, $excelNegative))->toBe(3145416634)
        ->and($service->excelSignedToDesiredMinor(AccountType::ContraAsset, $excelNegative))->toBe(3145416634)
        ->and($service->excelSignedToDesiredMinor(AccountType::CurrentLiability, 1110034693700))->toBe(1110034693700)
        ->and($service->excelSignedToDesiredMinor(AccountType::NonOperatingExpense, -211444303))->toBe(211444303)
        ->and($service->excelSignedToDesiredMinor(AccountType::OperatingExpense, -150000))->toBe(150000)
        ->and($service->excelSignedToDesiredMinor(AccountType::OperatingRevenue, -50000))->toBe(50000);

    expect(\App\Helpers\AccountTypeHelper::map('Account Receivable', 'Penyisihan Provisi Kerugian Piutang'))
        ->toBe(AccountType::ContraAsset)
        ->and(\App\Helpers\AccountTypeHelper::map('Expense', 'Beban Penyisihan Piutang'))
        ->toBe(AccountType::OperatingExpense)
        ->and(\App\Helpers\AccountTypeHelper::map('Account Receivable', 'Piutang PT KPPB'))
        ->toBe(AccountType::CurrentAsset);
});

it('posts by account type normal balance and ignores excel minus sign', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);

    $equitySubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::Equity)
        ->firstOrFail();

    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $equitySubtype->id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '3200.ob',
        'name' => 'RETAINED EARNING',
    ]);

    $contraSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::ContraAsset)
        ->firstOrFail();

    $accumDep = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $contraSubtype->id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::ContraAsset,
        'code' => '1404.ob',
        'name' => 'Akumulasi Penyusutan Inventaris Kantor',
    ]);

    $allowance = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $contraSubtype->id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::ContraAsset,
        'code' => '1107.ob',
        'name' => 'Penyisihan Provisi Kerugian Piutang',
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $year = 2026;

    $accumExcel = $service->parseBalanceToMinor('-31.454.166,34');
    $allowExcel = $service->parseBalanceToMinor('-652.395.427,84');

    $mapping = [
        'version' => 1,
        'approved' => true,
        'approved_at' => now()->toIso8601String(),
        'company_id' => $company->id,
        'year' => $year,
        'rows' => [
            [
                'row_number' => 2,
                'excel_name' => $accumDep->name,
                'balance_minor' => $accumExcel,
                'status' => 'exact',
                'decision_status' => 'decided',
                'action' => 'import',
                'account_id' => $accumDep->id,
                'rename_to_excel' => false,
            ],
            [
                'row_number' => 3,
                'excel_name' => $allowance->name,
                'balance_minor' => $allowExcel,
                'status' => 'exact',
                'decision_status' => 'decided',
                'action' => 'import',
                'account_id' => $allowance->id,
                'rename_to_excel' => false,
            ],
        ],
    ];

    $service->importApprovedMapping($mapping, execute: true);

    $accumEntry = \App\Models\Accounting\JournalEntry::query()
        ->where('company_id', $company->id)
        ->where('account_id', $accumDep->id)
        ->where('description', "Saldo Awal FY-{$year} - {$accumDep->code}")
        ->first();

    $allowEntry = \App\Models\Accounting\JournalEntry::query()
        ->where('company_id', $company->id)
        ->where('account_id', $allowance->id)
        ->where('description', "Saldo Awal FY-{$year} - {$allowance->code}")
        ->first();

    // ContraAsset (akumulasi & penyisihan) → credit; minus Excel diabaikan.
    expect($accumEntry)->not->toBeNull()
        ->and($accumEntry->type)->toBe(\App\Enums\Accounting\JournalEntryType::Credit)
        ->and((int) $accumEntry->amount)->toBe(3145416634)
        ->and($allowEntry)->not->toBeNull()
        ->and($allowEntry->type)->toBe(\App\Enums\Accounting\JournalEntryType::Credit)
        ->and((int) $allowEntry->amount)->toBe(65239542784);

    $fyStart = $service->computeFiscalYearStartForYear($company, $year);
    /** @var AccountService $accountService */
    $accountService = app(AccountService::class);

    $accumStarting = $accountService->getStartingBalance(
        $accumDep->fresh(),
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );
    $allowStarting = $accountService->getStartingBalance(
        $allowance->fresh(),
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($accumStarting?->getAmount())->toBe(3145416634)
        ->and($allowStarting?->getAmount())->toBe(65239542784);
});

it('posts excel minus other expense as debit for bank administration fees', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);

    $equitySubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::Equity)
        ->firstOrFail();

    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $equitySubtype->id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '3200.ob',
        'name' => 'RETAINED EARNING',
    ]);

    $expenseSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('company_id', $company->id)
        ->where('type', AccountType::NonOperatingExpense)
        ->firstOrFail();

    $bankAdmin = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $expenseSubtype->id,
        'category' => AccountCategory::Expense,
        'type' => AccountType::NonOperatingExpense,
        'code' => '8001.ob',
        'name' => 'Beban Administrasi Bank',
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $year = 2026;
    $excelMinor = $service->parseBalanceToMinor('-2.114.443,03');

    $mapping = [
        'version' => 1,
        'approved' => true,
        'approved_at' => now()->toIso8601String(),
        'company_id' => $company->id,
        'year' => $year,
        'rows' => [
            [
                'row_number' => 2,
                'excel_name' => $bankAdmin->name,
                'balance_minor' => $excelMinor,
                'status' => 'exact',
                'decision_status' => 'decided',
                'action' => 'import',
                'account_id' => $bankAdmin->id,
                'rename_to_excel' => false,
            ],
        ],
    ];

    $service->importApprovedMapping($mapping, execute: true);

    $entry = \App\Models\Accounting\JournalEntry::query()
        ->where('company_id', $company->id)
        ->where('account_id', $bankAdmin->id)
        ->where('description', "Saldo Awal FY-{$year} - {$bankAdmin->code}")
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->type)->toBe(\App\Enums\Accounting\JournalEntryType::Debit)
        ->and((int) $entry->amount)->toBe(211444303);

    $fyStart = $service->computeFiscalYearStartForYear($company, $year);
    /** @var AccountService $accountService */
    $accountService = app(AccountService::class);
    $starting = $accountService->getStartingBalance(
        $bankAdmin->fresh(),
        $fyStart->toDateTimeString(),
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($starting?->getAmount())->toBe(211444303);
});

it('keeps balance sheet equation after importing asset liability equity and pnl leaves', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);
    $year = 2026;

    $liabilitySubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::CurrentLiability)
        ->firstOrFail();
    $equitySubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::Equity)
        ->firstOrFail();
    $revenueSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::OperatingRevenue)
        ->firstOrFail();
    $expenseSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::OperatingExpense)
        ->firstOrFail();

    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1100.ob',
        'name' => 'Kas Neraca Pnl',
    ]);
    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $liabilitySubtype->id,
        'category' => AccountCategory::Liability,
        'type' => AccountType::CurrentLiability,
        'code' => '2100.ob',
        'name' => 'Utang Neraca Pnl',
    ]);
    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $equitySubtype->id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '3200.ob',
        'name' => 'RETAINED EARNING',
    ]);
    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $revenueSubtype->id,
        'category' => AccountCategory::Revenue,
        'type' => AccountType::OperatingRevenue,
        'code' => '4100.ob',
        'name' => 'Pendapatan Neraca Pnl',
    ]);
    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $expenseSubtype->id,
        'category' => AccountCategory::Expense,
        'type' => AccountType::OperatingExpense,
        'code' => '5100.ob',
        'name' => 'Beban Neraca Pnl',
    ]);

    $excel = storage_path('framework/testing/obni-bs-equation-pnl.xlsx');
    obniMakeExcel($excel, [
        ['', '1100.ob', 'Kas Neraca Pnl', 'Cash/Bank', '10.000.000,00'],
        ['', '2100.ob', 'Utang Neraca Pnl', 'Account Payable', '3.000.000,00'],
        ['', '3004', 'Current Earning of The Year', 'Equity', '4.000.000,00'],
        ['', '3200.ob', 'RETAINED EARNING', 'Equity', '3.000.000,00'],
        ['', '4100.ob', 'Pendapatan Neraca Pnl', 'Revenue', '6.000.000,00'],
        ['', '5100.ob', 'Beban Neraca Pnl', 'Expense', '2.000.000,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $matches = $service->matchFromExcel($company->id, $excel);
    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);

    $byName = collect($mapping['rows'])->keyBy('excel_name');
    expect($byName['Current Earning of The Year']['action'])->toBe('skip')
        ->and($byName['Pendapatan Neraca Pnl']['action'])->toBe('import')
        ->and($byName['Beban Neraca Pnl']['action'])->toBe('import');

    $service->importApprovedMapping($mapping, execute: true);

    $fyStart = $service->computeFiscalYearStartForYear($company, $year);
    $asOf = $fyStart->copy()->subSecond()->toDateString();

    $report = \App\Facades\Reporting::buildBalanceSheetReport($asOf, [], null, $company->id);

    $assets = \App\Utilities\Currency\CurrencyConverter::convertToFloat(
        $report->categories['Assets']->summary->endingBalance ?? '0'
    );
    $liabilities = \App\Utilities\Currency\CurrencyConverter::convertToFloat(
        $report->categories['Liabilities']->summary->endingBalance ?? '0'
    );
    $equity = \App\Utilities\Currency\CurrencyConverter::convertToFloat(
        $report->categories['Equity']->summary->endingBalance ?? '0'
    );

    // Assets 10M = Liab 3M + Equity RE 3M + CYE (Rev 6M - Exp 2M) 4M
    expect(abs($assets - ($liabilities + $equity)))->toBeLessThan(0.02);
});

it('clears Opening Balance Equity to Retained Earnings so FY starting balance is zero', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    $openingEquity = obniConfigureOpeningEquity($reference);

    $retained = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '3200.01',
        'name' => 'RETAINED EARNING',
    ]);

    $cash = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::CurrentAsset,
        'code' => '1100.clear',
        'name' => 'Kas Clear OBE',
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $year = 2026;

    expect($service->postOpeningBalanceAdjustment($company->id, $cash, $year, 5_000_000_00)['posted'])->toBeTrue();

    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
    $accountService = app(AccountService::class);

    $obeBefore = $accountService->getStartingBalance($openingEquity, $fyStart, override: true, projectId: null, companyId: $company->id);
    expect($obeBefore?->getAmount() ?? 0)->not->toBe(0);

    $dry = $service->clearOpeningBalanceEquity($company->id, $year, execute: false);
    expect($dry['posted'])->toBeFalse()
        ->and($dry['already_clear'])->toBeFalse()
        ->and($dry['diff'])->toBe(-1 * (int) $obeBefore->getAmount())
        ->and($dry['retained_earnings_id'])->toBe($retained->id);

    $live = $service->clearOpeningBalanceEquity($company->id, $year, execute: true);
    expect($live['posted'])->toBeTrue()
        ->and($live['transaction_id'])->not->toBeNull();

    $obeAfter = $accountService->getStartingBalance($openingEquity, $fyStart, override: true, projectId: null, companyId: $company->id);
    expect($obeAfter?->getAmount() ?? 0)->toBe(0);

    expect(
        Transaction::query()
            ->where('company_id', $company->id)
            ->where('id', $live['transaction_id'])
            ->where('meta->addon', 'opening_balance_equity_clear')
            ->exists()
    )->toBeTrue();
});

it('importApprovedMapping ends with clear_obe so Opening Balance Equity starting FY is zero', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    $openingEquity = obniConfigureOpeningEquity($reference);

    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '3200.01',
        'name' => 'RETAINED EARNING',
    ]);

    $cash = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::CurrentAsset,
        'code' => '1100.imp',
        'name' => 'Kas Import Clear OBE',
    ]);

    $excel = storage_path('framework/testing/obni-import-clear-obe.xlsx');
    obniMakeExcel($excel, [
        ['', '1100.imp', 'Kas Import Clear OBE', 'Cash/Bank', '7.500.000,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $year = 2026;
    $matches = $service->matchFromExcel($company->id, $excel);
    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);

    $plan = $service->importApprovedMapping($mapping, execute: true);
    $clearRow = collect($plan)->first(fn ($r) => ($r[2] ?? '') === OpeningBalanceNameImportService::ACTION_CLEAR_OBE);

    expect($clearRow)->not->toBeNull();

    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
    $obeAfter = app(AccountService::class)->getStartingBalance(
        $openingEquity,
        $fyStart,
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($obeAfter?->getAmount() ?? 0)->toBe(0)
        ->and(
            Transaction::query()
                ->where('company_id', $company->id)
                ->where('meta->addon', 'opening_balance_equity_clear')
                ->exists()
        )->toBeTrue();
});

it('matches excel names via aliases even when punctuation or wording differs', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);

    $kppe = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1600.02',
        'name' => 'Piutang PT. KPPE',
    ]);

    $pajakGiro = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => AccountCategory::Expense,
        'type' => AccountType::NonOperatingExpense,
        'code' => '7200.03',
        'name' => 'Pajak Jasa Giro',
    ]);

    $excel = storage_path('framework/testing/obni-aliases.xlsx');
    obniMakeExcel($excel, [
        ['', '1102', 'Piutang PT KPPE', 'Account Receivable', '187.821.463,00'],
        ['', '8002', 'Biaya Pajak Jasa Giro', 'Other Expense', '0,00'],
        ['', '7002', 'Pemulihan Cadangan Piutang', 'Other Income', '0,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $matches = collect($service->matchFromExcel($company->id, $excel))->keyBy(fn ($m) => $m->excelName);

    expect($matches['Piutang PT KPPE']->status)->toBe('exact')
        ->and($matches['Piutang PT KPPE']->accountId)->toBe($kppe->id)
        ->and($matches['Piutang PT KPPE']->action)->toBe('import')
        ->and($matches['Biaya Pajak Jasa Giro']->status)->toBe('exact')
        ->and($matches['Biaya Pajak Jasa Giro']->accountId)->toBe($pajakGiro->id)
        ->and($matches['Biaya Pajak Jasa Giro']->action)->toBe('import')
        ->and($matches['Biaya Pajak Jasa Giro']->balanceMinor)->toBe(0)
        ->and($matches['Pemulihan Cadangan Piutang']->status)->toBe('skipped_zero')
        ->and($matches['Pemulihan Cadangan Piutang']->accountId)->toBeNull();
});

it('applies excel retained earnings amount after clear OBE so excel remains source of truth', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    $openingEquity = obniConfigureOpeningEquity($reference);

    $retained = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '3200.01',
        'name' => 'RETAINED EARNING',
    ]);

    $cash = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::CurrentAsset,
        'code' => '1100.loop',
        'name' => 'Kas Loop OBE',
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $year = 2026;
    $excelReMinor = $service->parseBalanceToMinor('2.000.000,00');

    $service->postOpeningBalanceAdjustment($company->id, $cash, $year, 1_000_000_00);
    $service->postOpeningBalanceAdjustment($company->id, $retained, $year, 5_000_000_00);
    $service->clearOpeningBalanceEquity($company->id, $year, execute: true);

    $excel = storage_path('framework/testing/obni-re-loop.xlsx');
    obniMakeExcel($excel, [
        ['', '1100.loop', 'Kas Loop OBE', 'Cash/Bank', '1.000.000,00'],
        ['', '3200.01', 'RETAINED EARNING', 'Equity', '2.000.000,00'],
    ]);

    $matches = $service->matchFromExcel($company->id, $excel);
    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);
    $plan = $service->importApprovedMapping($mapping, execute: true);

    $reRow = collect($plan)->first(fn ($r) => ($r[1] ?? '') === 'RETAINED EARNING');
    expect($reRow[2] ?? null)->toBe('import')
        ->and((string) ($reRow[3] ?? ''))->toContain('final excel');

    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
    $accountService = app(AccountService::class);
    $reAfter = $accountService->getStartingBalance($retained->fresh(), $fyStart, override: true, projectId: null, companyId: $company->id);

    expect($reAfter?->getAmount() ?? 0)->toBe($excelReMinor);
});

it('keeps excel Laba Ditahan and does not park orphan RETAINED EARNING into OBE', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    $openingEquity = obniConfigureOpeningEquity($reference);
    $year = 2026;

    $equitySubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::Equity)
        ->firstOrFail();

    $labaDitahan = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $equitySubtype->id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '3000-002',
        'name' => 'laba ditahan',
    ]);

    $orphanRe = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $equitySubtype->id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '320001',
        'name' => 'RETAINED EARNING',
    ]);

    $cash = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::CurrentAsset,
        'code' => '1100.ld',
        'name' => 'Kas Laba Ditahan Uji',
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $labaMinor = $service->parseBalanceToMinor('2.743.225.280,29');
    $orphanMinor = $service->parseBalanceToMinor('9.565.599.746,63');

    expect($service->postOpeningBalanceAdjustment($company->id, $cash, $year, $service->parseBalanceToMinor('10.000.000,00'))['posted'])->toBeTrue()
        ->and($service->postOpeningBalanceAdjustment($company->id, $labaDitahan, $year, $labaMinor)['posted'])->toBeTrue()
        ->and($service->postOpeningBalanceAdjustment($company->id, $orphanRe, $year, $orphanMinor)['posted'])->toBeTrue();

    $excel = storage_path('framework/testing/obni-laba-ditahan-excel.xlsx');
    obniMakeExcel($excel, [
        ['', '1100.ld', 'Kas Laba Ditahan Uji', 'Cash/Bank', '10.000.000,00'],
        ['', '3003', 'Laba Ditahan', 'Equity', '2.743.225.280,29'],
    ]);

    $matches = $service->matchFromExcel($company->id, $excel);
    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);
    $plan = $service->importApprovedMapping($mapping, execute: true);

    expect(collect($plan)->first(fn ($r) => ($r[1] ?? '') === 'RETAINED EARNING'))->toBeNull()
        ->and(collect($plan)->first(fn ($r) => ($r[1] ?? '') === 'Laba Ditahan')[2] ?? null)
        ->toBe('import');

    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
    $accountService = app(AccountService::class);

    expect($accountService->getStartingBalance($orphanRe->fresh(), $fyStart, override: true, projectId: null, companyId: $company->id)?->getAmount() ?? 0)
        ->toBe($orphanMinor)
        ->and($accountService->getStartingBalance($labaDitahan->fresh(), $fyStart, override: true, projectId: null, companyId: $company->id)?->getAmount() ?? 0)
        ->toBe($labaMinor);

    $clearRow = collect($plan)->first(fn ($r) => ($r[2] ?? '') === OpeningBalanceNameImportService::ACTION_CLEAR_OBE);
    expect((string) ($clearRow[3] ?? ''))->toContain('dilewati');
});

it('plugs unbalanced journals to opening equity so trial balance debit equals credit', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);
    $openingEquity = obniConfigureOpeningEquity($reference);

    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => AccountCategory::Equity,
        'type' => AccountType::Equity,
        'code' => '3200.01',
        'name' => 'RETAINED EARNING',
    ]);

    $cash = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::CurrentAsset,
        'code' => '1100.plug',
        'name' => 'Kas Plug TB',
    ]);

    // One-sided legacy journal (seperti Auto Import).
    $txn = Transaction::create([
        'company_id' => $company->id,
        'bank_account_id' => null,
        'type' => 'journal',
        'description' => 'Auto Import unbalanced test',
        'posted_at' => '2025-12-15 10:00:00',
        'amount' => 1_250_000_00,
        'reviewed' => true,
    ]);
    \App\Models\Accounting\JournalEntry::create([
        'company_id' => $company->id,
        'transaction_id' => $txn->id,
        'account_id' => $cash->id,
        'type' => \App\Enums\Accounting\JournalEntryType::Debit,
        'amount' => 1_250_000_00,
        'description' => 'One-sided',
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $dry = $service->balanceUnbalancedJournalsToOpeningEquity($company->id, '2025-12-31', execute: false);
    expect($dry['fixed'])->toBeGreaterThan(0)
        ->and($dry['net_debit_excess'])->toBe(1_250_000_00);

    $live = $service->balanceUnbalancedJournalsToOpeningEquity($company->id, '2025-12-31', execute: true);
    expect($live['fixed'])->toBeGreaterThan(0);

    $service->clearOpeningBalanceEquity($company->id, 2026, execute: true);

    $report = app(\App\Services\ReportService::class)->buildTrialBalanceReport(
        'standard',
        '2025-12-31',
        [],
        null,
        $company->id,
    );
    $debit = \App\Utilities\Currency\CurrencyConverter::convertToFloat($report->overallTotal->debitBalance);
    $credit = \App\Utilities\Currency\CurrencyConverter::convertToFloat($report->overallTotal->creditBalance);

    expect(abs($debit - $credit))->toBeLessThan(0.02);
});

it('prefers parent-qualified account over bare exact name for duplicate Excel child names', function () {
    $company = $this->testCompany;
    $year = 2026;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);

    $bare = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1108-5',
        'name' => 'Piutang Pemegang Saham',
    ]);
    $qualified = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1108-7',
        'name' => 'Aset lain lain - piutang pemegang saham',
    ]);

    $excel = storage_path('framework/testing/obni-parent-qualified-pemegang.xlsx');
    obniMakeExcel($excel, [
        ['1100', '', 'Piutang Usaha', 'Account Receivable', '0,00'],
        ['', '1201', 'Piutang Pemegang Saham', 'Account Receivable', '0,00'],
        ['1500', '', 'Aset Lain-lain', 'Other Current Asset', '39.600.000,00'],
        ['', '1502', 'Piutang Pemegang Saham', 'Other Current Asset', '39.600.000,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $desired = $service->parseBalanceToMinor('39.600.000,00');

    $matches = $service->matchFromExcel($company->id, $excel);
    $pemegang = $matches->where('excelName', 'Piutang Pemegang Saham')->values();

    expect($pemegang)->toHaveCount(2)
        ->and($pemegang[0]->excelParentName)->toBe('Piutang Usaha')
        ->and($pemegang[0]->status)->toBe('exact')
        ->and($pemegang[0]->action)->toBe('import')
        ->and($pemegang[0]->balanceMinor)->toBe(0)
        ->and($pemegang[0]->accountId)->toBe($bare->id)
        ->and($pemegang[1]->excelParentName)->toBe('Aset Lain-lain')
        ->and($pemegang[1]->status)->toBe('exact')
        ->and($pemegang[1]->action)->toBe('import')
        ->and($pemegang[1]->accountId)->toBe($qualified->id);

    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);
    $service->importApprovedMapping($mapping, execute: true);

    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
    $accountService = app(AccountService::class);

    $bareStart = $accountService->getStartingBalance(
        $bare->fresh(),
        $fyStart,
        override: true,
        projectId: null,
        companyId: $company->id,
    );
    $qualifiedStart = $accountService->getStartingBalance(
        $qualified->fresh(),
        $fyStart,
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($bareStart?->getAmount() ?? 0)->toBe(0)
        ->and($qualifiedStart?->getAmount())->toBe($desired);
});

it('zeros stale opening balance when excel matched row has balance 0', function () {
    $company = $this->testCompany;
    $year = 2026;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);

    $bare = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1108-5',
        'name' => 'Piutang Pemegang Saham',
    ]);
    $qualified = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1108-7',
        'name' => 'Aset lain lain - piutang pemegang saham',
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $stale = $service->parseBalanceToMinor('39.600.000,00');
    expect($service->postOpeningBalanceAdjustment($company->id, $bare, $year, $stale)['posted'])->toBeTrue();

    $excel = storage_path('framework/testing/obni-zero-stale-pemegang.xlsx');
    obniMakeExcel($excel, [
        ['1100', '', 'Piutang Usaha', 'Account Receivable', '0,00'],
        ['', '1201', 'Piutang Pemegang Saham', 'Account Receivable', '0,00'],
        ['1500', '', 'Aset Lain-lain', 'Other Current Asset', '39.600.000,00'],
        ['', '1502', 'Piutang Pemegang Saham', 'Other Current Asset', '39.600.000,00'],
    ]);

    $matches = $service->matchFromExcel($company->id, $excel);
    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);
    $service->importApprovedMapping($mapping, execute: true);

    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
    $accountService = app(AccountService::class);

    $bareStart = $accountService->getStartingBalance(
        $bare->fresh(),
        $fyStart,
        override: true,
        projectId: null,
        companyId: $company->id,
    );
    $qualifiedStart = $accountService->getStartingBalance(
        $qualified->fresh(),
        $fyStart,
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect($bareStart?->getAmount() ?? 0)->toBe(0)
        ->and($qualifiedStart?->getAmount())->toBe($stale);
});

it('keeps KPPB-style unique shareholder receivable name on exact match without parent override', function () {
    $company = $this->testCompany;
    $reference = obniCashReference($company->id);

    $long = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1203',
        'name' => 'Piutang Pemegang Saham atas setoran modal',
    ]);
    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $reference->subtype_id,
        'category' => $reference->category,
        'type' => $reference->type,
        'code' => '1107',
        'name' => 'Piutang Pemegang Saham',
    ]);

    $excel = storage_path('framework/testing/obni-kppb-pemegang-setoran.xlsx');
    obniMakeExcel($excel, [
        ['1100', '', 'Piutang Usaha', 'Account Receivable', '5.010.000,00'],
        ['', '1203', 'Piutang Pemegang Saham atas setoran modal', 'Account Receivable', '5.010.000,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $matches = $service->matchFromExcel($company->id, $excel);
    $row = $matches->firstWhere('excelName', 'Piutang Pemegang Saham atas setoran modal');

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('exact')
        ->and($row->action)->toBe('import')
        ->and($row->accountId)->toBe($long->id);
});

it('does not let duplicate PPh 21 under BYMHD zero the Utang Pajak PPh 21 balance', function () {
    $company = $this->testCompany;
    $year = 2026;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);

    $liabilitySubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::CurrentLiability)
        ->firstOrFail();
    $assetSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::CurrentAsset)
        ->firstOrFail();

    $piutangPajak = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $assetSubtype->id,
        'category' => AccountCategory::Asset,
        'type' => AccountType::CurrentAsset,
        'code' => '1107-007',
        'name' => 'Piutang Pajak',
    ]);
    $pph21Utang = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $liabilitySubtype->id,
        'category' => AccountCategory::Liability,
        'type' => AccountType::CurrentLiability,
        'parent_id' => $piutangPajak->id,
        'code' => '1107-007.01',
        'name' => 'PPh 21',
    ]);
    $pph29 = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $liabilitySubtype->id,
        'category' => AccountCategory::Liability,
        'type' => AccountType::CurrentLiability,
        'parent_id' => $piutangPajak->id,
        'code' => '1107-007-000008',
        'name' => 'PPh 29',
    ]);
    $bymhd = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $liabilitySubtype->id,
        'category' => AccountCategory::Liability,
        'type' => AccountType::CurrentLiability,
        'code' => '2100',
        'name' => 'Biaya yang masih harus dibayar',
    ]);
    $pph21Bymhd = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $liabilitySubtype->id,
        'category' => AccountCategory::Liability,
        'type' => AccountType::CurrentLiability,
        'parent_id' => $bymhd->id,
        'code' => '2100.02',
        'name' => 'PPh 21 yang masih harus dibayar',
    ]);

    $excel = storage_path('framework/testing/obni-pph21-duplicate-parent.xlsx');
    obniMakeExcel($excel, [
        ['2100', '', 'Utang Pajak', 'Other Current Liability', '13.448.061,10'],
        ['', '2103', 'PPh 21', 'Other Current Liability', '5.128.124,00'],
        ['', '2104', 'PPh 29', 'Other Current Liability', '8.319.937,10'],
        ['2200', '', 'Biaya Yang Masih Harus Dibayar', 'Other Current Liability', '0,00'],
        ['', '2202', 'PPh 21', 'Other Current Liability', '0,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $desired21 = $service->parseBalanceToMinor('5.128.124,00');
    $desired29 = $service->parseBalanceToMinor('8.319.937,10');

    $matches = $service->matchFromExcel($company->id, $excel);
    $pph21Rows = $matches->where('excelName', 'PPh 21')->values();

    expect($pph21Rows)->toHaveCount(2)
        ->and($pph21Rows[0]->excelParentName)->toBe('Utang Pajak')
        ->and($pph21Rows[0]->accountId)->toBe($pph21Utang->id)
        ->and($pph21Rows[1]->excelParentName)->toBe('Biaya Yang Masih Harus Dibayar')
        ->and($pph21Rows[1]->accountId)->toBe($pph21Bymhd->id)
        ->and($matches->firstWhere('excelName', 'PPh 29')->accountId)->toBe($pph29->id);

    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);
    $service->importApprovedMapping($mapping, execute: true);

    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
    $accountService = app(AccountService::class);

    expect($accountService->getStartingBalance($pph21Utang->fresh(), $fyStart, override: true, projectId: null, companyId: $company->id)?->getAmount())
        ->toBe($desired21)
        ->and($accountService->getStartingBalance($pph29->fresh(), $fyStart, override: true, projectId: null, companyId: $company->id)?->getAmount())
        ->toBe($desired29)
        ->and($accountService->getStartingBalance($pph21Bymhd->fresh(), $fyStart, override: true, projectId: null, companyId: $company->id)?->getAmount() ?? 0)
        ->toBe(0);
});

it('imports BYMHD leaf with same name as parent without ambiguous BPJS sibling', function () {
    $company = $this->testCompany;
    $year = 2026;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);

    $liabilitySubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::CurrentLiability)
        ->firstOrFail();

    $parent = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $liabilitySubtype->id,
        'category' => AccountCategory::Liability,
        'type' => AccountType::CurrentLiability,
        'code' => '2100',
        'name' => 'Biaya yang masih harus dibayar',
    ]);
    $bpjs = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $liabilitySubtype->id,
        'category' => AccountCategory::Liability,
        'type' => AccountType::CurrentLiability,
        'parent_id' => $parent->id,
        'code' => '2100.01',
        'name' => 'BPJS yang masih harus dibayar',
    ]);
    $leaf = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $liabilitySubtype->id,
        'category' => AccountCategory::Liability,
        'type' => AccountType::CurrentLiability,
        'parent_id' => $parent->id,
        'code' => '2100.03',
        'name' => 'Biaya yang masih harus dibayar',
    ]);

    $excel = storage_path('framework/testing/obni-bymhd-same-name-leaf.xlsx');
    obniMakeExcel($excel, [
        ['2200', '', 'Biaya Yang Masih Harus Dibayar', 'Other Current Liability', '2.345.428.178,00'],
        ['', '2201', 'BPJS', 'Other Current Liability', '0,00'],
        ['', '2204', 'Biaya Yang Masih Harus Dibayar', 'Other Current Liability', '2.345.428.178,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $desired = $service->parseBalanceToMinor('2.345.428.178,00');

    $matches = $service->matchFromExcel($company->id, $excel);
    $leafMatch = $matches->first(fn ($m) => $m->excelCode === '2204');
    $bpjsMatch = $matches->first(fn ($m) => $m->excelCode === '2201');

    expect($leafMatch)->not->toBeNull()
        ->and($leafMatch->status)->toBe('exact')
        ->and($leafMatch->action)->toBe('import')
        ->and($leafMatch->accountId)->toBe($leaf->id)
        ->and($bpjsMatch->accountId)->toBe($bpjs->id);

    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);
    $service->importApprovedMapping($mapping, execute: true);

    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
    $accountService = app(AccountService::class);

    expect($accountService->getStartingBalance($leaf->fresh(), $fyStart, override: true, projectId: null, companyId: $company->id)?->getAmount())
        ->toBe($desired)
        ->and($accountService->getStartingBalance($parent->fresh(), $fyStart, override: true, projectId: null, companyId: $company->id)?->getAmount() ?? 0)
        ->toBe(0);
});

it('maps Pendapatan Tenaga Kerja SDM/Wellsite to short Pendapatan SDM/Wellsite names', function () {
    $company = $this->testCompany;
    $year = 2026;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);

    $revenueSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::OperatingRevenue)
        ->firstOrFail();

    $sdm = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $revenueSubtype->id,
        'category' => AccountCategory::Revenue,
        'type' => AccountType::OperatingRevenue,
        'code' => '4000-004',
        'name' => 'Pendapatan SDM',
    ]);
    $wellsite = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $revenueSubtype->id,
        'category' => AccountCategory::Revenue,
        'type' => AccountType::OperatingRevenue,
        'code' => '4000-005',
        'name' => 'Pendapatan Wellsite',
    ]);
    // Noise: similar "Pendapatan Tenaga Kerja *" must not win over short names.
    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $revenueSubtype->id,
        'category' => AccountCategory::Revenue,
        'type' => AccountType::OperatingRevenue,
        'code' => '4000-021',
        'name' => 'Pendapatan Tenaga Kerja EE',
    ]);
    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $revenueSubtype->id,
        'category' => AccountCategory::Revenue,
        'type' => AccountType::OperatingRevenue,
        'code' => '5000-025',
        'name' => 'Pendapatan Tenaga Kerja SCM',
    ]);

    $excel = storage_path('framework/testing/obni-pendapatan-tenaga-kerja.xlsx');
    obniMakeExcel($excel, [
        ['4000', '', 'PENDAPATAN', 'Revenue', '1.263.144.073,00'],
        ['', '4002', 'Pendapatan Tenaga Kerja SDM', 'Revenue', '783.141.967,00'],
        ['', '4003', 'Pendapatan Tenaga Kerja Wellsite', 'Revenue', '480.002.106,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $desiredSdm = $service->parseBalanceToMinor('783.141.967,00');
    $desiredWellsite = $service->parseBalanceToMinor('480.002.106,00');

    $matches = $service->matchFromExcel($company->id, $excel);
    expect($matches->firstWhere('excelName', 'Pendapatan Tenaga Kerja SDM')->accountId)->toBe($sdm->id)
        ->and($matches->firstWhere('excelName', 'Pendapatan Tenaga Kerja SDM')->action)->toBe('import')
        ->and($matches->firstWhere('excelName', 'Pendapatan Tenaga Kerja Wellsite')->accountId)->toBe($wellsite->id)
        ->and($matches->firstWhere('excelName', 'Pendapatan Tenaga Kerja Wellsite')->action)->toBe('import');

    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);
    $service->importApprovedMapping($mapping, execute: true);

    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
    $accountService = app(AccountService::class);

    expect($accountService->getStartingBalance($sdm->fresh(), $fyStart, override: true, projectId: null, companyId: $company->id)?->getAmount())
        ->toBe($desiredSdm)
        ->and($accountService->getStartingBalance($wellsite->fresh(), $fyStart, override: true, projectId: null, companyId: $company->id)?->getAmount())
        ->toBe($desiredWellsite);
});

it('maps Biaya penyusutan Peralatan kantor to existing Biaya Penyusutan Peralatan without code clash', function () {
    $company = $this->testCompany;
    $year = 2026;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);

    $expenseSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::OperatingExpense)
        ->firstOrFail();

    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $expenseSubtype->id,
        'category' => AccountCategory::Expense,
        'type' => AccountType::OperatingExpense,
        'code' => '6000',
        'name' => 'Biaya Umum & Administrasi',
    ]);
    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $expenseSubtype->id,
        'category' => AccountCategory::Expense,
        'type' => AccountType::OperatingExpense,
        'code' => '6000.01',
        'name' => 'Biaya Gaji',
    ]);
    $penyusutan = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $expenseSubtype->id,
        'category' => AccountCategory::Expense,
        'type' => AccountType::OperatingExpense,
        'code' => '6002-003',
        'name' => 'Biaya Penyusutan Peralatan',
    ]);

    $excel = storage_path('framework/testing/obni-penyusutan-code-clash.xlsx');
    obniMakeExcel($excel, [
        ['6000', '', 'BEBAN UMUM DAN ADMINISTRASI', 'Expense', '11.071.196,50'],
        ['', '6006', 'Biaya penyusutan Peralatan kantor', 'Expense', '11.071.196,50'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $desired = $service->parseBalanceToMinor('11.071.196,50');

    $matches = $service->matchFromExcel($company->id, $excel);
    $row = $matches->firstWhere('excelName', 'Biaya penyusutan Peralatan kantor');

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('fuzzy')
        ->and($row->candidates[0]['id'] ?? null)->toBe($penyusutan->id);

    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);
    $service->importApprovedMapping($mapping, execute: true);

    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
    expect(app(AccountService::class)->getStartingBalance(
        $penyusutan->fresh(),
        $fyStart,
        override: true,
        projectId: null,
        companyId: $company->id,
    )?->getAmount())->toBe($desired);

    expect(Account::query()->where('company_id', $company->id)->where('name', 'Biaya penyusutan Peralatan kantor')->exists())
        ->toBeFalse();
});

it('keeps Biaya Pajak distinct from Beban Pajak and still matches KPG-style unique Biaya Pajak', function () {
    $company = $this->testCompany;
    $year = 2026;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);

    $expenseSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::OperatingExpense)
        ->firstOrFail();

    $biayaPajak = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $expenseSubtype->id,
        'category' => AccountCategory::Expense,
        'type' => AccountType::OperatingExpense,
        'code' => '6001-009',
        'name' => 'Biaya Pajak',
    ]);
    Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $expenseSubtype->id,
        'category' => AccountCategory::Expense,
        'type' => AccountType::OperatingExpense,
        'code' => '6004',
        'name' => 'Beban Pajak',
    ]);

    $excel = storage_path('framework/testing/obni-biaya-vs-beban-pajak.xlsx');
    obniMakeExcel($excel, [
        ['6000', '', 'BEBAN UMUM DAN ADMINISTRASI', 'Expense', '280.441.760,00'],
        ['', '6002', 'Biaya Pajak', 'Expense', '280.441.760,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $desired = $service->parseBalanceToMinor('280.441.760,00');

    $matches = $service->matchFromExcel($company->id, $excel);
    $row = $matches->firstWhere('excelName', 'Biaya Pajak');

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('exact')
        ->and($row->action)->toBe('import')
        ->and($row->accountId)->toBe($biayaPajak->id);

    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);
    $service->importApprovedMapping($mapping, execute: true);

    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
    expect(app(AccountService::class)->getStartingBalance(
        $biayaPajak->fresh(),
        $fyStart,
        override: true,
        projectId: null,
        companyId: $company->id,
    )?->getAmount())->toBe($desired);
});

it('does not post non-zero excel leaf onto structural parent header (KPPE Beban Lain-lain)', function () {
    $company = $this->testCompany;
    $year = 2026;
    $reference = obniCashReference($company->id);
    obniConfigureOpeningEquity($reference);

    $expenseSubtype = \App\Models\Accounting\AccountSubtype::query()
        ->where('type', AccountType::NonOperatingExpense)
        ->firstOrFail();

    // Header seperti KPG/KPPB/KPPE: "Beban Lain-Lain" + child admin bank (prefix code).
    $header = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $expenseSubtype->id,
        'category' => AccountCategory::Expense,
        'type' => AccountType::NonOperatingExpense,
        'code' => '7100-004',
        'name' => 'Beban Lain-Lain',
    ]);
    $adminBank = Account::factory()->create([
        'company_id' => $company->id,
        'subtype_id' => $expenseSubtype->id,
        'parent_id' => $header->id,
        'category' => AccountCategory::Expense,
        'type' => AccountType::NonOperatingExpense,
        'code' => '7100-004.01',
        'name' => 'Beban administrasi bank',
    ]);

    $excel = storage_path('framework/testing/obni-beban-lain-homonym.xlsx');
    obniMakeExcel($excel, [
        ['8000', '', 'Beban Lain-Lain', 'Other Expense', '-13.274.229,20'],
        ['', '8001', 'Beban administrasi bank', 'Other Expense', '-1.914.229,20'],
        ['', '8003', 'Beban Lain-lain', 'Other Expense', '-11.360.000,00'],
    ]);

    /** @var OpeningBalanceNameImportService $service */
    $service = app(OpeningBalanceNameImportService::class);
    $matches = $service->matchFromExcel($company->id, $excel);

    $adminRow = $matches->firstWhere('excelCode', '8001');
    $leafRow = $matches->firstWhere('excelCode', '8003');

    expect($adminRow->status)->toBe('exact')
        ->and($adminRow->accountId)->toBe($adminBank->id)
        ->and($leafRow->status)->toBe('new')
        ->and($leafRow->accountId)->toBeNull()
        ->and($leafRow->suggestedCode)->not->toBeNull();

    // Saldo 0 ke header (pola KPG) tetap exact — tidak diubah.
    $excelZero = storage_path('framework/testing/obni-beban-lain-zero-header.xlsx');
    obniMakeExcel($excelZero, [
        ['8000', '', 'Beban Lain-Lain', 'Other Expense', '-1.914.229,20'],
        ['', '8001', 'Beban administrasi bank', 'Other Expense', '-1.914.229,20'],
        ['', '8003', 'Beban Lain-lain', 'Other Expense', '0,00'],
    ]);
    $zeroRow = $service->matchFromExcel($company->id, $excelZero)->firstWhere('excelCode', '8003');
    expect($zeroRow->status)->toBe('exact')
        ->and($zeroRow->accountId)->toBe($header->id)
        ->and($zeroRow->action)->toBe('import');

    $mapping = $service->buildAutoApprovedMappingFromMatches($company->id, $year, $excel, $matches);
    $service->importApprovedMapping($mapping, execute: true);

    $createdLeaf = Account::query()
        ->where('company_id', $company->id)
        ->where('code', $leafRow->suggestedCode)
        ->first();

    expect($createdLeaf)->not->toBeNull()
        ->and(Account::trialBalanceExcludedAccountIdsForCompany($company->id)->contains($createdLeaf->id))->toBeFalse()
        ->and(Account::trialBalanceExcludedAccountIdsForCompany($company->id)->contains($header->id))->toBeTrue();

    $fyStart = $service->computeFiscalYearStartForYear($company, $year)->toDateTimeString();
    $desired = $service->parseBalanceToMinor('-11.360.000,00');
    // Other expense Excel negatif → di app ending/starting mengikuti posting OB (debit positif).
    $leafStart = app(AccountService::class)->getStartingBalance(
        $createdLeaf->fresh(),
        $fyStart,
        override: true,
        projectId: null,
        companyId: $company->id,
    );
    $headerStart = app(AccountService::class)->getStartingBalance(
        $header->fresh(),
        $fyStart,
        override: true,
        projectId: null,
        companyId: $company->id,
    );

    expect(abs($leafStart?->getAmount() ?? 0))->toBe(abs($desired))
        ->and($headerStart?->getAmount() ?? 0)->toBe(0);
});
