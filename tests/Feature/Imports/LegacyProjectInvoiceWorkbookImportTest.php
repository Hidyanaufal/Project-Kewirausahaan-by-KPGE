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
        ->and($paidInvoice->subtotal)->toBe(90_200_000)
        ->and($paidInvoice->profit_total)->toBe(9_020_000)
        ->and($paidInvoice->tax_total)->toBe(10_914_200)
        ->and($paidInvoice->total)->toBe(110_134_200)
        ->and($paidInvoice->actual_total)->toBe(97_235_600)
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
