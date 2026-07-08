<?php

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountDefaultKey;
use App\Enums\Accounting\InvoiceStatus;
use App\Imports\LegacyProjectInvoiceWorkbookImport;
use App\Models\AccountDefault;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Models\Accounting\Invoice;
use App\Models\Addons\Project;
use App\Models\Addons\ProjectType;
use App\Scopes\CurrentCompanyScope;
use App\Utilities\Currency\CurrencyAccessor;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    $this->withOfferings();

    ensureLegacyInvoiceImportDefaultsForCompany($this->testCompany->id);

    ProjectType::query()->firstOrCreate([
        'company_id' => $this->testCompany->id,
        'name' => 'Pengadaan',
    ], [
        'code' => 'KG',
    ]);

    ProjectType::query()->firstOrCreate([
        'company_id' => $this->testCompany->id,
        'name' => 'EO',
    ], [
        'code' => 'EO',
    ]);

    $this->legacyWorkbookPath = sys_get_temp_dir() . '/legacy-import-' . uniqid() . '.xlsx';
    createLegacyWorkbook($this->legacyWorkbookPath);
});

afterEach(function () {
    if (isset($this->legacyWorkbookPath) && file_exists($this->legacyWorkbookPath)) {
        @unlink($this->legacyWorkbookPath);
    }
});

it('imports legacy workbook into approved projects and invoice final states', function () {
    $importer = new LegacyProjectInvoiceWorkbookImport(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
    );

    $importer->importFile($this->legacyWorkbookPath);
    expect(Project::query()->where('is_imported', true)->count())->toBe(3);

    $pengadaan = Project::query()->where('number', 'KG26-001')->firstOrFail();
    $eo = Project::query()->where('number', 'EO26-001')->firstOrFail();
    $fallbackProject = Project::query()->where('number', 'EO26-175')->firstOrFail();

    expect($pengadaan->status)->toBe(\App\Enums\Addons\ProjectStatus::Approved)
        ->and($pengadaan->is_imported)->toBeTrue()
        ->and($pengadaan->created_at?->toDateString())->toBe('2026-01-05')
        ->and($pengadaan->updated_at?->toDateString())->toBe('2026-01-05')
        ->and($eo->status)->toBe(\App\Enums\Addons\ProjectStatus::Approved)
        ->and($eo->is_imported)->toBeTrue()
        ->and($eo->created_at?->toDateString())->toBe('2026-01-08')
        ->and($eo->updated_at?->toDateString())->toBe('2026-01-08')
        ->and($fallbackProject->name)->toBe('Data Lama - EO26-175');

    $paidInvoice = Invoice::query()->where('invoice_number', 'PG26-001')->firstOrFail();
    $cancelledInvoice = Invoice::query()->where('invoice_number', 'PG26-002')->firstOrFail();
    $sentInvoice = Invoice::query()->where('invoice_number', 'PG26-003')->firstOrFail();

    expect($paidInvoice->status)->toBe(InvoiceStatus::Paid)
        ->and($paidInvoice->is_imported)->toBeTrue()
        ->and($paidInvoice->created_at?->toDateString())->toBe('2026-01-10')
        ->and($paidInvoice->updated_at?->toDateString())->toBe('2026-01-10')
        ->and($paidInvoice->approvalTransaction)->not->toBeNull()
        ->and($paidInvoice->payments()->count())->toBe(1)
        ->and($paidInvoice->project?->number)->toBe('EO26-001')
        ->and($paidInvoice->subtotal)->toBe(902_000_000)
        ->and($paidInvoice->profit_total)->toBe(9_020_000_000)
        ->and($paidInvoice->tax_total)->toBe(1_091_420_000)
        ->and($paidInvoice->total)->toBe(11_013_420_000)
        ->and($paidInvoice->actual_total)->toBe(9_723_560_000)
        ->and($paidInvoice->amount_due)->toBe(0);

    expect($cancelledInvoice->status)->toBe(InvoiceStatus::Cancelled)
        ->and($cancelledInvoice->is_imported)->toBeTrue()
        ->and($cancelledInvoice->transactions()->count())->toBe(0);

    expect($sentInvoice->is_imported)->toBeTrue()
        ->and($sentInvoice->approvalTransaction)->not->toBeNull()
        ->and($sentInvoice->status)->toBe(InvoiceStatus::Sent);
});

it('is idempotent on repeated import without creating duplicate records', function () {
    $importer = new LegacyProjectInvoiceWorkbookImport(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
    );

    $importer->importFile($this->legacyWorkbookPath);
    $projectIdsAfterFirstImport = Project::query()
        ->whereIn('number', ['KG26-001', 'EO26-001', 'EO26-175'])
        ->pluck('id')
        ->all();
    $invoiceIdsAfterFirstImport = Invoice::query()
        ->whereIn('invoice_number', ['PG26-001', 'PG26-002', 'PG26-003'])
        ->pluck('id')
        ->all();

    $importer->importFile($this->legacyWorkbookPath);

    expect(Project::query()->where('is_imported', true)->count())->toBe(3)
        ->and(Invoice::query()->where('is_imported', true)->count())->toBe(3)
        ->and(Project::query()->whereIn('number', ['KG26-001', 'EO26-001', 'EO26-175'])->pluck('id')->all())->toBe($projectIdsAfterFirstImport)
        ->and(Invoice::query()->whereIn('invoice_number', ['PG26-001', 'PG26-002', 'PG26-003'])->pluck('id')->all())->toBe($invoiceIdsAfterFirstImport)
        ->and(Project::query()->where('number', 'KG26-001')->value('updated_at')?->toDateString())->toBe('2026-01-05')
        ->and(Invoice::query()->where('invoice_number', 'PG26-001')->value('updated_at')?->toDateString())->toBe('2026-01-10');
});

it('imports invoice from invoice sheet when supplement row has zero financials', function () {
    $workbookPath = sys_get_temp_dir() . '/legacy-import-empty-supplement-' . uniqid() . '.xlsx';
    createLegacyWorkbookWithEmptySupplement($workbookPath);

    Project::query()->create([
        'company_id' => $this->testCompany->id,
        'project_type_id' => ProjectType::query()->where('name', 'EO')->value('id'),
        'client_id' => \App\Models\Common\Client::factory()->create(['company_id' => $this->testCompany->id])->id,
        'pic_id' => $this->testUser->id,
        'number' => 'EO26-331',
        'name' => 'Legacy EO Project',
        'status' => \App\Enums\Addons\ProjectStatus::Approved,
        'is_imported' => true,
    ]);

    $importer = new LegacyProjectInvoiceWorkbookImport(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
    );

    $importer->importFile($workbookPath);
    @unlink($workbookPath);

    $invoice = Invoice::query()->where('invoice_number', 'PG26-495')->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->subtotal)->toBe(21_585_004_400)
        ->and($invoice->tax_total)->toBe(2_374_350_500)
        ->and($invoice->total)->toBe(23_959_354_900)
        ->and($invoice->actual_total)->toBe(21_153_304_300)
        ->and($invoice->payments()->count())->toBe(1)
        ->and($invoice->approvalTransaction?->amount)->toBeGreaterThan(0);
});

it('prefers invoice sheet financials when supplement is cancelled with zero values', function () {
    $workbookPath = sys_get_temp_dir() . '/legacy-import-batal-supplement-' . uniqid() . '.xlsx';
    createLegacyWorkbookWithCancelledSupplement($workbookPath);

    Project::query()->create([
        'company_id' => $this->testCompany->id,
        'project_type_id' => ProjectType::query()->where('name', 'EO')->value('id'),
        'client_id' => \App\Models\Common\Client::factory()->create(['company_id' => $this->testCompany->id])->id,
        'pic_id' => $this->testUser->id,
        'number' => 'EO26-057',
        'name' => 'Legacy EO Project 057',
        'status' => \App\Enums\Addons\ProjectStatus::Approved,
        'is_imported' => true,
    ]);

    $importer = new LegacyProjectInvoiceWorkbookImport(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
    );

    $importer->importFile($workbookPath);
    @unlink($workbookPath);

    $invoice = Invoice::query()->where('invoice_number', 'PG26-057')->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->total)->toBe(11_013_420_000)
        ->and($invoice->payments()->count())->toBe(1);
});

it('derives subtotal and profit from shifted invoice columns', function () {
    $workbookPath = sys_get_temp_dir() . '/legacy-import-shifted-' . uniqid() . '.xlsx';
    createLegacyWorkbookWithShiftedColumns($workbookPath);

    Project::query()->create([
        'company_id' => $this->testCompany->id,
        'project_type_id' => ProjectType::query()->where('name', 'EO')->value('id'),
        'client_id' => \App\Models\Common\Client::factory()->create(['company_id' => $this->testCompany->id])->id,
        'pic_id' => $this->testUser->id,
        'number' => 'EO26-170',
        'name' => 'Legacy EO Project 170',
        'status' => \App\Enums\Addons\ProjectStatus::Approved,
        'is_imported' => true,
    ]);

    $importer = new LegacyProjectInvoiceWorkbookImport(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
    );

    $importer->importFile($workbookPath);
    @unlink($workbookPath);

    $invoice = Invoice::query()->where('invoice_number', 'PG26-170')->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->subtotal)->toBe(2_721_600_000)
        ->and($invoice->profit_total)->toBe(336_000_000)
        ->and($invoice->tax_total)->toBe(336_336_000)
        ->and($invoice->total)->toBe(3_393_936_000)
        ->and($invoice->actual_total)->toBe(2_996_448_000);
});

it('normalizes two digit year dates from legacy excel strings', function () {
    $service = new \App\Services\LegacyProjectInvoiceImportService(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
    );

    $reflection = new ReflectionClass($service);
    $parseDate = $reflection->getMethod('parseDate');
    $parseDate->setAccessible(true);

    expect($parseDate->invoke($service, '06/01/25'))->toBe('2025-01-06')
        ->and($parseDate->invoke($service, '18/12/25'))->toBe('2025-12-18')
        ->and($parseDate->invoke($service, '17-Okt-25'))->toBe('2025-10-17')
        ->and($parseDate->invoke($service, '1 Nov 0256'))->toBe('2025-11-01')
        ->and($parseDate->invoke($service, 45991))->toBe('2025-11-30');
});

it('creates legacy project stub when invoice references missing project', function () {
    $workbookPath = sys_get_temp_dir() . '/legacy-import-missing-project-' . uniqid() . '.xlsx';
    createLegacyWorkbookWithMissingProjectReference($workbookPath);

    $importer = new LegacyProjectInvoiceWorkbookImport(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
    );

    $importer->importFile($workbookPath);
    @unlink($workbookPath);

    $project = Project::query()->where('number', 'EO26-70001')->first();
    $invoice = Invoice::query()->where('invoice_number', 'PG26-70001')->first();

    expect($project)->not->toBeNull()
        ->and($project->is_imported)->toBeTrue()
        ->and($invoice)->not->toBeNull()
        ->and($invoice->project_id)->toBe($project->id)
        ->and($invoice->total)->toBeGreaterThan(0);
});

it('rejects imported invoice rows with zero total unless cancelled', function () {
    $workbookPath = sys_get_temp_dir() . '/legacy-import-zero-total-' . uniqid() . '.xlsx';
    createLegacyWorkbookWithZeroTotalRow($workbookPath);

    Project::query()->create([
        'company_id' => $this->testCompany->id,
        'project_type_id' => ProjectType::query()->where('name', 'EO')->value('id'),
        'client_id' => \App\Models\Common\Client::factory()->create(['company_id' => $this->testCompany->id])->id,
        'pic_id' => $this->testUser->id,
        'number' => 'EO26-999',
        'name' => 'Legacy EO Project 999',
        'status' => \App\Enums\Addons\ProjectStatus::Approved,
        'is_imported' => true,
    ]);

    $importer = new LegacyProjectInvoiceWorkbookImport(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
    );

    $importer->importFile($workbookPath);
    @unlink($workbookPath);

    expect(Invoice::query()->where('invoice_number', 'PG26-999')->exists())->toBeFalse()
        ->and($importer->notificationBody())->toContain('rejected (total 0)');
});

it('imports kpp aligned workbook with fuzzy project type match', function () {
    ProjectType::query()
        ->where('company_id', $this->testCompany->id)
        ->where('name', 'Pengadaan')
        ->update([
            'name' => 'Pengadaan Barang & Jasa',
            'code' => 'KP',
        ]);

    ProjectType::query()->firstOrCreate([
        'company_id' => $this->testCompany->id,
        'name' => 'Tenaga Kerja',
    ], [
        'code' => 'TA',
    ]);

    $workbookPath = sys_get_temp_dir() . '/legacy-import-kpp-' . uniqid() . '.xlsx';
    createKppLegacyWorkbook($workbookPath);

    $importer = new LegacyProjectInvoiceWorkbookImport(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
    );

    $importer->importFile($workbookPath);
    @unlink($workbookPath);

    $project = Project::query()->where('number', 'KP26-001')->firstOrFail();
    $invoice = Invoice::query()->where('invoice_number', 'PP26-001')->firstOrFail();

    expect($project->is_imported)->toBeTrue()
        ->and($project->type->name)->toBe('Pengadaan Barang & Jasa')
        ->and($invoice->is_imported)->toBeTrue()
        ->and($invoice->project_id)->toBe($project->id)
        ->and($invoice->total)->toBeGreaterThan(0);
});

it('creates contract stub project from supplement pekerjaan hint for orphan invoice', function () {
    ProjectType::query()->firstOrCreate([
        'company_id' => $this->testCompany->id,
        'name' => 'Tenaga Kerja',
    ], [
        'code' => 'TA',
    ]);

    $workbookPath = sys_get_temp_dir() . '/legacy-import-kpp-contract-' . uniqid() . '.xlsx';
    createKppLegacyWorkbookWithContractInvoice($workbookPath);

    $importer = new LegacyProjectInvoiceWorkbookImport(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
    );

    $importer->importFile($workbookPath);
    @unlink($workbookPath);

    $project = Project::query()->where('work_basis', '4650017814')->first();
    $invoice = Invoice::query()->where('invoice_number', 'PP26-010')->first();

    expect($project)->not->toBeNull()
        ->and($project->is_imported)->toBeTrue()
        ->and($project->type->name)->toBe('Tenaga Kerja')
        ->and($invoice)->not->toBeNull()
        ->and($invoice->project_id)->toBe($project->id);
});

it('detects kpp supplement layout from header', function () {
    $header = ['No', 'Huruf', 'No Urut', 'No Invoice', 'Tanggal', 'No.Kontrak/No.Pengadaan/No.SPK', 'Nomor Pekerjaan', 'PT', 'FUNGSI', 'Judul Invoice'];
    $layout = \App\Services\LegacyWorkbookLayout::detect([], [['values' => ['PP26-001', '2026-01-02', '4650017814']]], $header);

    expect($layout->invoiceLayout)->toBe(\App\Services\LegacyWorkbookLayout::INVOICE_ALIGNED)
        ->and($layout->supplementLayout)->toBe(\App\Services\LegacyWorkbookLayout::SUPPLEMENT_KPP)
        ->and($layout->invoiceCol('invoice_number'))->toBe(0)
        ->and($layout->supplementCol('pekerjaan'))->toBe(6);
});

it('prefers invoice row with project reference when workbook has duplicate invoice numbers', function () {
    $workbookPath = sys_get_temp_dir() . '/legacy-import-duplicate-invoice-' . uniqid() . '.xlsx';
    createKppLegacyWorkbookWithDuplicateInvoiceRows($workbookPath);

    Project::query()->create([
        'company_id' => $this->testCompany->id,
        'project_type_id' => ProjectType::query()->where('name', 'Pengadaan Barang & Jasa')->value('id')
            ?? ProjectType::query()->where('name', 'Pengadaan')->value('id'),
        'client_id' => \App\Models\Common\Client::factory()->create(['company_id' => $this->testCompany->id])->id,
        'pic_id' => $this->testUser->id,
        'number' => 'KP26-009',
        'name' => 'Legacy KP Project',
        'status' => \App\Enums\Addons\ProjectStatus::Approved,
        'is_imported' => true,
    ]);

    $importer = new LegacyProjectInvoiceWorkbookImport(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
    );

    $importer->importFile($workbookPath);
    @unlink($workbookPath);

    $invoice = Invoice::query()->where('invoice_number', 'PP26-232')->firstOrFail();

    expect($invoice->project?->number)->toBe('KP26-009')
        ->and($invoice->approvalTransaction)->not->toBeNull()
        ->and($invoice->lineItems()->count())->toBe(1);
});

function ensureLegacyInvoiceImportDefaultsForCompany(int $companyId): void
{
    $defaultCurrency = CurrencyAccessor::getDefaultCurrency();

    $assetSubtype = AccountSubtype::query()
        ->where('company_id', $companyId)
        ->where('category', AccountCategory::Asset)
        ->first();

    $subtypeId = $assetSubtype?->id ?? AccountSubtype::query()
        ->where('company_id', $companyId)
        ->value('id');

    $defaultsToEnsure = [
        AccountDefaultKey::PPh23->value => 'PPh 23 (Legacy Import Test)',
        AccountDefaultKey::PPn->value => 'PPN (Legacy Import Test)',
        AccountDefaultKey::Profit->value => 'Profit (Legacy Import Test)',
        AccountDefaultKey::AccountsReceivable->value => 'Accounts Receivable (Legacy Import Test)',
        AccountDefaultKey::SalesDiscount->value => 'Sales Discount (Legacy Import Test)',
    ];

    foreach ($defaultsToEnsure as $key => $accountName) {
        $exists = AccountDefault::query()
            ->withoutGlobalScope(CurrentCompanyScope::class)
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->exists();

        if ($exists) {
            continue;
        }

        $account = Account::factory()->create([
            'company_id' => $companyId,
            'subtype_id' => $subtypeId,
            'name' => $accountName,
            'currency_code' => $defaultCurrency,
            'archived' => false,
            'default' => false,
        ]);

        AccountDefault::query()->create([
            'company_id' => $companyId,
            'account_id' => $account->id,
            'key' => $key,
        ]);
    }
}

it('prefers invoice sheet financials over supplement when both are present', function () {
    $workbookPath = sys_get_temp_dir() . '/legacy-import-invoice-sheet-priority-' . uniqid() . '.xlsx';
    createShiftedInvoiceSheetPriorityWorkbook($workbookPath);

    Project::query()->create([
        'company_id' => $this->testCompany->id,
        'project_type_id' => ProjectType::query()->where('name', 'EO')->value('id'),
        'client_id' => \App\Models\Common\Client::factory()->create(['company_id' => $this->testCompany->id])->id,
        'pic_id' => $this->testUser->id,
        'number' => 'EO25-143',
        'name' => 'Legacy EO Project 143',
        'status' => \App\Enums\Addons\ProjectStatus::Approved,
        'is_imported' => true,
    ]);

    $importer = new LegacyProjectInvoiceWorkbookImport(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
    );

    $importer->importFile($workbookPath);
    @unlink($workbookPath);

    $invoice = Invoice::query()->where('invoice_number', 'PG25-160')->firstOrFail();

    expect($invoice->total)->toBe(3_370_018_200)
        ->and($invoice->tax_total)->toBe(333_965_800)
        ->and($invoice->actual_total)->toBe(2_975_331_400);
});

it('marks past-due invoice as overdue when invoice sheet status and paid value are N/A', function () {
    $workbookPath = sys_get_temp_dir() . '/legacy-import-overdue-na-' . uniqid() . '.xlsx';
    createNaStatusInvoiceWorkbook($workbookPath);

    Project::query()->create([
        'company_id' => $this->testCompany->id,
        'project_type_id' => ProjectType::query()->where('name', 'EO')->value('id'),
        'client_id' => \App\Models\Common\Client::factory()->create(['company_id' => $this->testCompany->id])->id,
        'pic_id' => $this->testUser->id,
        'number' => 'EO25-193',
        'name' => 'Legacy EO Project 193',
        'status' => \App\Enums\Addons\ProjectStatus::Approved,
        'is_imported' => true,
    ]);

    $importer = new LegacyProjectInvoiceWorkbookImport(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
    );

    $importer->importFile($workbookPath);
    @unlink($workbookPath);

    $overdue = Invoice::query()->where('invoice_number', 'PG25-263')->firstOrFail();

    expect($overdue->status)->toBe(InvoiceStatus::Overdue)
        ->and($overdue->approvalTransaction)->not->toBeNull()
        ->and((int) $overdue->amount_paid)->toBe(0)
        ->and($overdue->payments()->count())->toBe(0)
        ->and($overdue->total)->toBe(12_245_520_000);
});

it('marks past-due invoice as overdue when invoice sheet has paid value but N/A status', function () {
    $workbookPath = sys_get_temp_dir() . '/legacy-import-partial-na-' . uniqid() . '.xlsx';
    createNaStatusWithPaidWorkbook($workbookPath);

    Project::query()->create([
        'company_id' => $this->testCompany->id,
        'project_type_id' => ProjectType::query()->where('name', 'EO')->value('id'),
        'client_id' => \App\Models\Common\Client::factory()->create(['company_id' => $this->testCompany->id])->id,
        'pic_id' => $this->testUser->id,
        'number' => 'EO25-194',
        'name' => 'Legacy EO Project 194',
        'status' => \App\Enums\Addons\ProjectStatus::Approved,
        'is_imported' => true,
    ]);

    $importer = new LegacyProjectInvoiceWorkbookImport(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
    );

    $importer->importFile($workbookPath);
    @unlink($workbookPath);

    $invoice = Invoice::query()->where('invoice_number', 'PG25-264')->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Overdue)
        ->and($invoice->approvalTransaction)->not->toBeNull()
        ->and((int) $invoice->amount_paid)->toBeGreaterThan(0);
});

it('keeps invoice as sent when status is N/A but due date is still in the future', function () {
    $workbookPath = sys_get_temp_dir() . '/legacy-import-future-na-' . uniqid() . '.xlsx';
    createNaStatusFutureDueWorkbook($workbookPath);

    Project::query()->create([
        'company_id' => $this->testCompany->id,
        'project_type_id' => ProjectType::query()->where('name', 'EO')->value('id'),
        'client_id' => \App\Models\Common\Client::factory()->create(['company_id' => $this->testCompany->id])->id,
        'pic_id' => $this->testUser->id,
        'number' => 'EO25-195',
        'name' => 'Legacy EO Project 195',
        'status' => \App\Enums\Addons\ProjectStatus::Approved,
        'is_imported' => true,
    ]);

    $importer = new LegacyProjectInvoiceWorkbookImport(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
    );

    $importer->importFile($workbookPath);
    @unlink($workbookPath);

    $invoice = Invoice::query()->where('invoice_number', 'PG25-265')->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->approvalTransaction)->not->toBeNull()
        ->and((int) $invoice->amount_paid)->toBe(0);
});

function createNaStatusInvoiceWorkbook(string $path): void
{
    $spreadsheet = new Spreadsheet;

    $invoice = $spreadsheet->getActiveSheet();
    $invoice->setTitle('Invoice');
    $invoice->fromArray([
        ['Nomor Invoice', '', 'Nomor Projek', 'Nomor Faktur', 'Offering Item', 'DPP', 'Profit', 'PPN', 'Total Setelah PPN', 'Status Bayar', 'Nilai Invoice yang dibayar'],
        ['', '16/09/2025', 'PG25-263', 'EO25-193', 'Jasa Event Organizer (EO) Kegiatan Investor Site Visit PT PGE Tbk', '', '10,692,000', '12,135,200', '122,455,200', '#N/A', '#N/A'],
    ]);

    $supplement = new Worksheet($spreadsheet, 't');
    $spreadsheet->addSheet($supplement);
    $supplement->fromArray([
        ['Bulan', 'Huruf', '', 'No Urut', 'No Invoice', 'Tanggal', 'No.Kontrak / SPK', 'PT', 'NO EO ', 'Judul Invoice', 'Nominal Invoice', 'Profit', 'Total stlh Profit', 'PPN', 'Total stlh PPN', 'Jatuh Tempo', 'POSISI Terakhir', 'PIC KPG', 'NO FAKTUR', 'Status Bayar', 'No Invoice', 'Nilai Invoice yang dibayar'],
        ['September', 'PG25-', '', '263', 'PG25-263', '2025-09-16', '', 'PGE', 'EO25-193', 'Jasa Event Organizer (EO) Kegiatan Investor Site Visit PT PGE Tbk', '89,100,000', '10,692,000', '99,792,000', '10,977,120', '110,769,120', '', '', 'CHATALYNA', '', 'PAID', 'PG25-263', '92726078'],
    ]);

    $projectEo = new Worksheet($spreadsheet, 'Project-EO');
    $spreadsheet->addSheet($projectEo);
    $projectEo->fromArray([
        ['Nama Projek', 'Tanggal Permintaan', 'Nomor Prefix', 'Nomor Registrasi', 'Nomor Projek'],
        ['Investor Site Visit', '01/09/2025', 'EO25-', '193', '193'],
    ]);

    (new Xlsx($spreadsheet))->save($path);
}

function createNaStatusWithPaidWorkbook(string $path): void
{
    $spreadsheet = new Spreadsheet;

    $invoice = $spreadsheet->getActiveSheet();
    $invoice->setTitle('Invoice');
    $invoice->fromArray([
        ['Nomor Invoice', '', 'Nomor Projek', 'Nomor Faktur', 'Offering Item', 'DPP', 'Profit', 'PPN', 'Total Setelah PPN', 'Status Bayar', 'Nilai Invoice yang dibayar'],
        ['', '16/09/2025', 'PG25-264', 'EO25-194', 'Jasa EO Legacy Overdue', '', '10,692,000', '12,135,200', '122,455,200', '#N/A', '90,000,000'],
    ]);

    $supplement = new Worksheet($spreadsheet, 't');
    $spreadsheet->addSheet($supplement);
    $supplement->fromArray([
        ['Bulan', 'Huruf', '', 'No Urut', 'No Invoice', 'Tanggal', 'No.Kontrak / SPK', 'PT', 'NO EO ', 'Judul Invoice', 'Nominal Invoice', 'Profit', 'Total stlh Profit', 'PPN', 'Total stlh PPN', 'Jatuh Tempo', 'POSISI Terakhir', 'PIC KPG', 'NO FAKTUR', 'Status Bayar', 'No Invoice', 'Nilai Invoice yang dibayar'],
        ['September', 'PG25-', '', '264', 'PG25-264', '2025-09-16', '', 'PGE', 'EO25-194', 'Jasa EO Legacy Overdue', '89,100,000', '10,692,000', '99,792,000', '10,977,120', '110,769,120', '', '', 'CHATALYNA', '', 'PAID', 'PG25-264', '92726078'],
    ]);

    $projectEo = new Worksheet($spreadsheet, 'Project-EO');
    $spreadsheet->addSheet($projectEo);
    $projectEo->fromArray([
        ['Nama Projek', 'Tanggal Permintaan', 'Nomor Prefix', 'Nomor Registrasi', 'Nomor Projek'],
        ['Legacy Overdue', '01/09/2025', 'EO25-', '194', '194'],
    ]);

    (new Xlsx($spreadsheet))->save($path);
}

function createNaStatusFutureDueWorkbook(string $path): void
{
    $spreadsheet = new Spreadsheet;

    $invoice = $spreadsheet->getActiveSheet();
    $invoice->setTitle('Invoice');
    $invoice->fromArray([
        ['Nomor Invoice', '', 'Nomor Projek', 'Nomor Faktur', 'Offering Item', 'DPP', 'Profit', 'PPN', 'Total Setelah PPN', 'Status Bayar', 'Nilai Invoice yang dibayar'],
        ['', '16/09/2025', 'PG25-265', 'EO25-195', 'Jasa EO Legacy Not Yet Due', '', '10,692,000', '12,135,200', '122,455,200', '#N/A', '#N/A'],
    ]);

    $supplement = new Worksheet($spreadsheet, 't');
    $spreadsheet->addSheet($supplement);
    $supplement->fromArray([
        ['Bulan', 'Huruf', '', 'No Urut', 'No Invoice', 'Tanggal', 'No.Kontrak / SPK', 'PT', 'NO EO ', 'Judul Invoice', 'Nominal Invoice', 'Profit', 'Total stlh Profit', 'PPN', 'Total stlh PPN', 'Jatuh Tempo', 'POSISI Terakhir', 'PIC KPG', 'NO FAKTUR', 'Status Bayar', 'No Invoice', 'Nilai Invoice yang dibayar'],
        ['September', 'PG25-', '', '265', 'PG25-265', '2025-09-16', '', 'PGE', 'EO25-195', 'Jasa EO Legacy Not Yet Due', '89,100,000', '10,692,000', '99,792,000', '10,977,120', '110,769,120', '2030-06-15', '', 'CHATALYNA', '', '#N/A', 'PG25-265', '#N/A'],
    ]);

    $projectEo = new Worksheet($spreadsheet, 'Project-EO');
    $spreadsheet->addSheet($projectEo);
    $projectEo->fromArray([
        ['Nama Projek', 'Tanggal Permintaan', 'Nomor Prefix', 'Nomor Registrasi', 'Nomor Projek'],
        ['Legacy Not Yet Due', '01/09/2025', 'EO25-', '195', '195'],
    ]);

    (new Xlsx($spreadsheet))->save($path);
}

function createShiftedInvoiceSheetPriorityWorkbook(string $path): void
{
    $spreadsheet = new Spreadsheet;

    $invoice = $spreadsheet->getActiveSheet();
    $invoice->setTitle('Invoice');
    $invoice->fromArray([
        ['Nomor Invoice', '', 'Nomor Projek', 'Nomor Faktur', 'Offering Item', 'DPP', 'Profit', 'PPN', 'Total Setelah PPN', 'Status Bayar', 'Nilai Invoice yang dibayar'],
        ['', '2/7/2025', 'PG25-160', 'EO25-143', 'PENERBITAN SERTIFIKAT PENDAFTARAN MEREK FLOW2MAX', '', '212', '3,339,658', '33,700,182', 'PAID', '29,753,314'],
    ]);

    $supplement = new Worksheet($spreadsheet, 't');
    $spreadsheet->addSheet($supplement);
    $supplement->fromArray([
        ['Bulan', 'Huruf', '', 'No Urut', 'No Invoice', 'Tanggal', 'No.Kontrak / SPK', 'PT', 'NO EO ', 'Judul Invoice', 'Nominal Invoice', 'Profit', 'Total stlh Profit', 'PPN', 'Total stlh PPN', 'Jatuh Tempo', 'POSISI Terakhir', 'PIC KPG', 'NO FAKTUR', 'Status Bayar', 'No Invoice', 'Nilai Invoice yang dibayar'],
        ['Juli', 'PG25-', '', '160', 'PG25-160', '2025-07-02', '', 'PGE', 'EO25-143', 'PENERBITAN SERTIFIKAT PENDAFTARAN MEREK FLOW2MAX', '1,765,000', '211,800', '1,976,800', '217,448', '2,194,248', '', '', 'ALIF', '', 'PAID', 'PG25-160', '2,154,712'],
    ]);

    $projectEo = new Worksheet($spreadsheet, 'Project-EO');
    $spreadsheet->addSheet($projectEo);
    $projectEo->fromArray([
        ['Nama Projek', 'Tanggal Permintaan', 'Nomor Prefix', 'Nomor Registrasi', 'Nomor Projek'],
        ['FLOW2MAX', '01/07/2025', 'EO25-', '143', '143'],
    ]);

    (new Xlsx($spreadsheet))->save($path);
}

function createLegacyWorkbook(string $path): void
{
    $spreadsheet = new Spreadsheet();

    $projectPengadaan = $spreadsheet->getActiveSheet();
    $projectPengadaan->setTitle('Project-Pengadaan');
    $projectPengadaan->fromArray([
        ['Nama Projek', 'Tanggal Permintaan', 'Nomor Prefix', 'Nomor Registrasi', 'Nomor Projek', 'Dasar Pekerjaan', 'Nilai Projek', 'Tanggal Mulai', 'Tanggal Selesai', 'Client', 'PIC Client', 'PIC Internal', 'Keterangan'],
        ['Pengadaan Boiler', '05/01/2026', 'KG26-', '001', '001', 'SPK Pengadaan', '150,000,000', '06/01/2026', '20/01/2026', 'PT Client Pengadaan', 'Budi', 'ROBBY', 'Legacy pengadaan'],
    ]);

    $projectEo = new Worksheet($spreadsheet, 'Project-EO');
    $spreadsheet->addSheet($projectEo);
    $projectEo->fromArray([
        ['Nama Projek', 'Tanggal Permintaan', 'Nomor Prefix', 'Nomor Registrasi', 'Nomor Projek', 'Dasar Pekerjaan', 'Nilai Projek', 'Tanggal Mulai', 'Tanggal Selesai', 'Client', 'PIC Client', 'PIC Internal'],
        ['Workshop Well Surveillance', '08/01/2026', 'EO26-', '001', '001', 'SPK EO', '99,220,000', '10/01/2026', '12/01/2026', 'PT Client EO', 'Sari', 'FOUNDRA'],
        ['', '09/01/2026', 'EO26-', '175', '175', '', '', '', '', '', '', ''],
    ]);

    $invoice = new Worksheet($spreadsheet, 'Invoice');
    $spreadsheet->addSheet($invoice);
    $invoice->fromArray([
        ['Nomor Invoice', '', 'Nomor Projek', 'Nomor Faktur', 'Offering Item', 'DPP', 'Profit', 'PPN', 'Total Setelah PPN', 'Status Bayar', 'Nilai Invoice yang dibayar'],
        ['1', '10/01/2026', 'PG26-001', 'EO26-001', 'Jasa EO Workshop Well Surveillance', '', '90,200,000', '10,914,200', '110,134,200', 'PAID', '97,235,600'],
        ['2', '12/01/2026', 'PG26-002', 'KG001', 'Tagihan Pengadaan Boiler', '', '', '5,500,000', '55,500,000', 'BATAL', ''],
        ['3', '15/01/2026', 'PG26-003', 'EO26-001', 'Follow Up Workshop', '', '', '1,100,000', '11,100,000', '#N/A', ''],
    ]);

    $supplement = new Worksheet($spreadsheet, 't');
    $spreadsheet->addSheet($supplement);
    $supplement->fromArray([
        ['Bulan', 'Huruf', '', 'No Urut', 'No Invoice', 'Tanggal', 'No.Kontrak / SPK', 'PT', 'NO EO ', 'Judul Invoice', 'Nominal Invoice', 'Profit', 'Total stlh Profit', 'PPN', 'Total stlh PPN', 'Jatuh Tempo', 'POSISI Terakhir', 'PIC KPG', 'NO FAKTUR', 'Status Bayar', 'No Invoice', 'Nilai Invoice yang dibayar', 'KETERANGAN', 'NOMINAL INVOICE FAKTUR'],
        ['Januari', 'PG26-', '', '001', 'PG26-001', '2026-01-10', '', 'PT Client EO', 'EO26-999', 'Jasa EO Workshop Well Surveillance', '99,220,000', '90,200,000', '99,220,000', '10,914,200', '110,134,200', '2026-01-30', '', 'FOUNDRA', '', 'PAID', 'PG26-001', '97235600', '', ''],
        ['Januari', 'PG26-', '', '002', 'PG26-002', '2026-01-12', '', 'PT Client Pengadaan', 'KG001', 'Tagihan Pengadaan Boiler', '50,000,000', '', '50,000,000', '5,500,000', '55,500,000', '2026-02-10', '', 'ROBBY', '', 'BATAL', 'PG26-002', '', 'Batal legacy', ''],
        ['Januari', 'PG26-', '', '003', 'PG26-003', '2026-01-15', '', 'PT Client EO', 'EO26-001', 'Follow Up Workshop', '10,000,000', '', '10,000,000', '1,100,000', '11,100,000', '2027-01-30', '', 'FOUNDRA', '', '#N/A', 'PG26-003', '', '', ''],
    ]);

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);
}

function createLegacyWorkbookWithEmptySupplement(string $path): void
{
    $spreadsheet = new Spreadsheet();

    $invoice = $spreadsheet->getActiveSheet();
    $invoice->setTitle('Invoice');
    $invoice->fromArray([
        ['Nomor Invoice', '', 'Nomor Projek', 'Nomor Faktur', 'Offering Item', 'DPP', 'Profit', 'PPN', 'Total Setelah PPN', 'Status Bayar', 'Nilai Invoice yang dibayar'],
        ['', '19/01/2026', 'PG26-495', 'EO26-331', 'Jasa EO Legacy Import', '', '', '23.743.505', '239.593.549', 'PAID', '211.533.043'],
    ]);

    $supplement = new Worksheet($spreadsheet, 't');
    $spreadsheet->addSheet($supplement);
    $supplement->fromArray([
        ['Bulan', 'Huruf', '', 'No Urut', 'No Invoice', 'Tanggal', 'No.Kontrak / SPK', 'PT', 'NO EO ', 'Judul Invoice', 'Nominal Invoice', 'Profit', 'Total stlh Profit', 'PPN', 'Total stlh PPN', 'Jatuh Tempo', 'POSISI Terakhir', 'PIC KPG', 'NO FAKTUR', 'Status Bayar', 'No Invoice', 'Nilai Invoice yang dibayar'],
        ['Januari', 'PG26-', '', '495', 'PG26-495', '', '', '', '', '', '', '', 0, 0, 0, '', '', '', '', '#N/A', 'PG26-495', '#N/A'],
    ]);

    $projectEo = new Worksheet($spreadsheet, 'Project-EO');
    $spreadsheet->addSheet($projectEo);
    $projectEo->fromArray([
        ['Nama Projek', 'Tanggal Permintaan', 'Nomor Prefix', 'Nomor Registrasi', 'Nomor Projek'],
        ['Legacy EO Project', '01/01/2026', 'EO26-', '331', '331'],
    ]);

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);
}

function createLegacyWorkbookWithCancelledSupplement(string $path): void
{
    $spreadsheet = new Spreadsheet();

    $invoice = $spreadsheet->getActiveSheet();
    $invoice->setTitle('Invoice');
    $invoice->fromArray([
        ['Nomor Invoice', '', 'Nomor Projek', 'Nomor Faktur', 'Offering Item', 'DPP', 'Profit', 'PPN', 'Total Setelah PPN', 'Status Bayar', 'Nilai Invoice yang dibayar'],
        ['', '10/01/2026', 'PG26-057', 'EO26-057', 'Jasa EO Paid From Invoice Sheet', '', '', '10.914.200', '110.134.200', 'PAID', '97.235.600'],
    ]);

    $supplement = new Worksheet($spreadsheet, 't');
    $spreadsheet->addSheet($supplement);
    $supplement->fromArray([
        ['Bulan', 'Huruf', '', 'No Urut', 'No Invoice', 'Tanggal', 'No.Kontrak / SPK', 'PT', 'NO EO ', 'Judul Invoice', 'Nominal Invoice', 'Profit', 'Total stlh Profit', 'PPN', 'Total stlh PPN', 'Jatuh Tempo', 'POSISI Terakhir', 'PIC KPG', 'NO FAKTUR', 'Status Bayar', 'No Invoice', 'Nilai Invoice yang dibayar'],
        ['Januari', 'PG26-', '', '057', 'PG26-057', '', '', '', '', '', 0, 0, 0, 0, 0, '', '', '', '', 'BATAL', 'PG26-057', '#N/A'],
    ]);

    $projectEo = new Worksheet($spreadsheet, 'Project-EO');
    $spreadsheet->addSheet($projectEo);
    $projectEo->fromArray([
        ['Nama Projek', 'Tanggal Permintaan', 'Nomor Prefix', 'Nomor Registrasi', 'Nomor Projek'],
        ['Legacy EO Project 057', '01/01/2026', 'EO26-', '057', '057'],
    ]);

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);
}

function createLegacyWorkbookWithShiftedColumns(string $path): void
{
    $spreadsheet = new Spreadsheet();

    $invoice = $spreadsheet->getActiveSheet();
    $invoice->setTitle('Invoice');
    $invoice->fromArray([
        ['Nomor Invoice', '', 'Nomor Projek', 'Nomor Faktur', 'Offering Item', 'DPP', 'Profit', 'PPN', 'Total Setelah PPN', 'Status Bayar', 'Nilai Invoice yang dibayar'],
        ['', '10/01/2026', 'PG26-170', 'EO26-170', 'Shifted Profit Column', '', '3.360.000', '3.363.360', '33.939.360', 'PAID', '29.964.480'],
    ]);

    $projectEo = new Worksheet($spreadsheet, 'Project-EO');
    $spreadsheet->addSheet($projectEo);
    $projectEo->fromArray([
        ['Nama Projek', 'Tanggal Permintaan', 'Nomor Prefix', 'Nomor Registrasi', 'Nomor Projek'],
        ['Legacy EO Project 170', '01/01/2026', 'EO26-', '170', '170'],
    ]);

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);
}

function createLegacyWorkbookWithZeroTotalRow(string $path): void
{
    $spreadsheet = new Spreadsheet();

    $invoice = $spreadsheet->getActiveSheet();
    $invoice->setTitle('Invoice');
    $invoice->fromArray([
        ['Nomor Invoice', '', 'Nomor Projek', 'Nomor Faktur', 'Offering Item', 'DPP', 'Profit', 'PPN', 'Total Setelah PPN', 'Status Bayar', 'Nilai Invoice yang dibayar'],
        ['', '10/01/2026', 'PG26-999', 'EO26-999', 'No Amount Invoice', '', '', '', '', 'SENT', ''],
    ]);

    $supplement = new Worksheet($spreadsheet, 't');
    $spreadsheet->addSheet($supplement);
    $supplement->fromArray([
        ['Bulan', 'Huruf', '', 'No Urut', 'No Invoice', 'Tanggal', 'No.Kontrak / SPK', 'PT', 'NO EO ', 'Judul Invoice', 'Nominal Invoice', 'Profit', 'Total stlh Profit', 'PPN', 'Total stlh PPN', 'Jatuh Tempo', 'POSISI Terakhir', 'PIC KPG', 'NO FAKTUR', 'Status Bayar', 'No Invoice', 'Nilai Invoice yang dibayar'],
        ['Januari', 'PG26-', '', '999', 'PG26-999', '', '', '', '', '', '', '', 0, 0, 0, '', '', '', '', '#N/A', 'PG26-999', '#N/A'],
    ]);

    $projectEo = new Worksheet($spreadsheet, 'Project-EO');
    $spreadsheet->addSheet($projectEo);
    $projectEo->fromArray([
        ['Nama Projek', 'Tanggal Permintaan', 'Nomor Prefix', 'Nomor Registrasi', 'Nomor Projek'],
        ['Legacy EO Project 999', '01/01/2026', 'EO26-', '999', '999'],
    ]);

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);
}

function createLegacyWorkbookWithMissingProjectReference(string $path): void
{
    $spreadsheet = new Spreadsheet();

    $invoice = $spreadsheet->getActiveSheet();
    $invoice->setTitle('Invoice');
    $invoice->fromArray([
        ['Nomor Invoice', '', 'Nomor Projek', 'Nomor Faktur', 'Offering Item', 'DPP', 'Profit', 'PPN', 'Total Setelah PPN', 'Status Bayar', 'Nilai Invoice yang dibayar'],
        ['', '20/01/2026', 'PG26-70001', 'EO26-70001', 'Missing Project Legacy Invoice', '', '', '1.100.000', '11.100.000', 'SENT', ''],
    ]);

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);
}

function createKppLegacyWorkbook(string $path): void
{
    $spreadsheet = new Spreadsheet();

    $projectPengadaan = $spreadsheet->getActiveSheet();
    $projectPengadaan->setTitle('Project-Pengadaan');
    $projectPengadaan->fromArray([
        ['Nama Projek', 'Tanggal Permintaan', 'Nomor Prefix', 'Nomor Registrasi', 'Nomor Projek', 'Dasar Pekerjaan', 'Nilai Projek', 'Tanggal Mulai', 'Tanggal Selesai', 'Client', 'PIC Client', 'PIC Internal'],
        ['Jasa Konsultasi KPP', '05/01/2026', 'KP26-', '001', 'KP26-001', '3900554898', '437.300.000', '06/01/2026', '20/01/2026', 'PT Client KPP', 'Budi', 'ROBBY'],
    ]);

    $invoice = new Worksheet($spreadsheet, 'Invoice');
    $spreadsheet->addSheet($invoice);
    $invoice->fromArray([
        ['Nomor Invoice', 'Tanggal', 'Nomor Projek', 'Nomor Faktur', 'Offering Item', 'DPP', 'Profit', 'PPN', 'Total Setelah PPN', 'Status Bayar', 'Nilai Invoice yang dibayar'],
        ['PP26-001', '2026-01-02', 'KP26-001', '3002500043984411', 'Tagihan KPP Legacy', '18,616,665', '1,117,000', '2,170,703', '21,904,368', 'PAID', '19,723,560'],
    ]);

    $supplement = new Worksheet($spreadsheet, 't');
    $spreadsheet->addSheet($supplement);
    $supplement->fromArray([
        ['No', 'Huruf', 'No Urut', 'No Invoice', 'Tanggal', 'No.Kontrak/No.Pengadaan/No.SPK', 'Nomor Pekerjaan', 'PT', 'FUNGSI', 'Judul Invoice', 'Nominal Invoice', 'Profit', 'Total stlh Profit', 'PPN', 'Total stlh PPN', 'POSISI Terakhir', 'PIC KPG', 'NO FAKTUR', 'Status Bayar', 'No Invoice', 'Keterangan'],
        ['1', 'PP26-', '001', 'PP26-001', '2026-01-02', '3900554898', 'Pengadaan Barang & Jasa', 'PT Client KPP', 'HC', 'Tagihan KPP Legacy', '18,616,665', '1,117,000', '19,733,665', '2,170,703', '21,904,368', '', 'ROBBY', '', 'PAID', 'PP26-001', ''],
    ]);

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);
}

function createKppLegacyWorkbookWithContractInvoice(string $path): void
{
    $spreadsheet = new Spreadsheet();

    $invoice = $spreadsheet->getActiveSheet();
    $invoice->setTitle('Invoice');
    $invoice->fromArray([
        ['Nomor Invoice', 'Tanggal', 'Nomor Projek', 'Nomor Faktur', 'Offering Item', 'DPP', 'Profit', 'PPN', 'Total Setelah PPN', 'Status Bayar', 'Nilai Invoice yang dibayar'],
        ['PP26-010', '2026-01-02', '4650017814', '3002500004132032', 'Tagihan Tenaga Kerja Legacy', '18,616,665', '1,117,000', '2,170,703', '21,904,368', 'PAID', '19,723,560'],
    ]);

    $supplement = new Worksheet($spreadsheet, 't');
    $spreadsheet->addSheet($supplement);
    $supplement->fromArray([
        ['No', 'Huruf', 'No Urut', 'No Invoice', 'Tanggal', 'No.Kontrak/No.Pengadaan/No.SPK', 'Nomor Pekerjaan', 'PT', 'FUNGSI', 'Judul Invoice', 'Nominal Invoice', 'Profit', 'Total stlh Profit', 'PPN', 'Total stlh PPN', 'POSISI Terakhir', 'PIC KPG', 'NO FAKTUR', 'Status Bayar', 'No Invoice', 'Keterangan'],
        ['10', 'PP26-', '010', 'PP26-010', '2026-01-02', '4650017814', 'Tenaga Kerja', 'PGE', 'HC', 'Tagihan Tenaga Kerja Legacy', '18,616,665', '1,117,000', '19,733,665', '2,170,703', '21,904,368', '', 'ROBBY', '', 'PAID', 'PP26-010', ''],
    ]);

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);
}

function createKppLegacyWorkbookWithDuplicateInvoiceRows(string $path): void
{
    $spreadsheet = new Spreadsheet();

    $invoice = $spreadsheet->getActiveSheet();
    $invoice->setTitle('Invoice');
    $invoice->fromArray([
        ['Nomor Invoice', 'Tanggal', 'Nomor Projek', 'Nomor Faktur', 'Offering Item', 'DPP', 'Profit', 'PPN', 'Total Setelah PPN', 'Status Bayar', 'Nilai Invoice yang dibayar'],
        ['PP26-232', '2026-07-04', 'KP26-009', '', 'Pembayaran Tahap III', '37,001,586', '4,440,190', '4,558,595', '46,000,372', 'PAID', '46,000,372'],
        ['PP26-232', '2026-07-04', '', '', 'Duplicate row without project', '37,001,586', '4,440,190', '4,558,595', '46,000,372', 'PAID', '46,000,372'],
    ]);

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);
}
