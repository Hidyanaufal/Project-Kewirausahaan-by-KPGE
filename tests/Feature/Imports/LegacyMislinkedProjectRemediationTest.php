<?php

use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Addons\ProjectStatus;
use App\Models\Accounting\Invoice;
use App\Models\Addons\Project;
use App\Models\Addons\ProjectType;
use App\Models\Common\Client;
use App\Models\Common\Offering;
use App\Services\LegacyMislinkedProjectRemediationService;

beforeEach(function () {
    $this->withOfferings();

    ProjectType::query()->firstOrCreate([
        'company_id' => $this->testCompany->id,
        'name' => 'Pengadaan',
    ], [
        'code' => 'KG',
    ]);
});

it('remediates imported invoice linked to mismatched project name', function () {
    $client = Client::factory()->create(['company_id' => $this->testCompany->id]);
    $offering = Offering::query()->where('company_id', $this->testCompany->id)->first()
        ?? Offering::factory()->create(['company_id' => $this->testCompany->id]);

    $wrongProject = Project::query()->create([
        'company_id' => $this->testCompany->id,
        'project_type_id' => ProjectType::query()->where('name', 'Pengadaan')->value('id'),
        'client_id' => $client->id,
        'pic_id' => $this->testUser->id,
        'number' => 'KG25-027',
        'name' => 'JASA SPOT CHARTER KENDARAAN BOD 11-12 Agust 2025',
        'status' => ProjectStatus::Approved,
        'is_imported' => true,
    ]);

    $invoice = new Invoice();
    $invoice->forceFill([
        'company_id' => $this->testCompany->id,
        'client_id' => $client->id,
        'project_id' => $wrongProject->id,
        'invoice_number' => 'PG25-210',
        'date' => '2025-07-31',
        'due_date' => '2025-07-31',
        'status' => InvoiceStatus::Paid,
        'currency_code' => 'IDR',
        'discount_method' => DocumentDiscountMethod::PerLineItem,
        'subtotal' => 12_366_957_700,
        'profit_total' => 4_365_842_300,
        'tax_total' => 1_840_608_000,
        'total' => 18_573_408_000,
        'actual_total' => 16_398_144_000,
        'amount_paid' => 16_398_144_000,
        'hide_offering_items' => true,
        'is_imported' => true,
        'created_by' => $this->testUser->id,
        'updated_by' => $this->testUser->id,
    ])->saveQuietly();

    $invoice->lineItems()->create([
        'company_id' => $this->testCompany->id,
        'offering_id' => $offering->id,
        'description' => 'Tagihan Termin VI 10% Pekerjaan penanaman pohon rehabilitasi daerah aliran sungai (DAS)',
        'quantity' => 1,
        'unit' => 'lot',
        'unit_price' => 12_366_957_700,
        'tax_total' => 0,
        'discount_total' => 0,
        'line_number' => 1,
        'created_by' => $this->testUser->id,
        'updated_by' => $this->testUser->id,
    ]);

    $summary = app(LegacyMislinkedProjectRemediationService::class)->remediate(
        companyId: $this->testCompany->id,
        actorId: $this->testUser->id,
        invoiceNumbers: ['PG25-210'],
    );

    $invoice->refresh();
    $newProject = $invoice->project;

    expect($summary['remediated'])->toBe(1)
        ->and($invoice->project_id)->not->toBe($wrongProject->id)
        ->and($newProject?->number)->toBe('LEG-PG25-210')
        ->and($newProject?->name)->toContain('penanaman pohon');
});
