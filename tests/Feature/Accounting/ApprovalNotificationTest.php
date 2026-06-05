<?php

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountDefaultKey;
use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Addons\ProjectStatus;
use App\Jobs\SendInvoiceApprovedNotification;
use App\Jobs\SendProjectApprovedNotification;
use App\Jobs\SendQuotationApprovedNotification;
use App\Mail\InvoiceApprovedNotification;
use App\Mail\ProjectApprovedNotification;
use App\Mail\QuotationApprovedNotification;
use App\Models\AccountDefault;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Models\Accounting\Estimate;
use App\Models\Accounting\Invoice;
use App\Models\Addons\Project;
use App\Models\Addons\ProjectType;
use App\Models\Addons\TaxCode;
use App\Models\Common\Client;
use App\Models\User;
use App\Scopes\CurrentCompanyScope;
use App\Utilities\Currency\CurrencyAccessor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    /** @var \Tests\TestCase $this */
    $this->withOfferings();
    Mail::fake();
    Queue::fake();
    Log::spy();
});

function ensureInvoiceAccountDefaultsForApprovalTests(int $companyId): void
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
        AccountDefaultKey::PPh23->value => 'PPh 23 (Approval Test)',
        AccountDefaultKey::PPn->value => 'PPN (Approval Test)',
        AccountDefaultKey::Profit->value => 'Profit (Approval Test)',
        AccountDefaultKey::AccountsReceivable->value => 'Accounts Receivable (Approval Test)',
        AccountDefaultKey::SalesDiscount->value => 'Sales Discount (Approval Test)',
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

function createDraftProjectForApprovalTests(int $companyId, int $picId, ?User $requestor = null): Project
{
    $projectType = ProjectType::query()->firstOrCreate([
        'company_id' => $companyId,
        'name' => 'Pengadaan',
    ], [
        'code' => 'KG',
    ]);

    $client = Client::query()
        ->where('company_id', $companyId)
        ->first()
        ?? Client::factory()->create([
            'company_id' => $companyId,
        ]);

    $requestor ??= User::find($picId);

    return Project::query()->create([
        'company_id' => $companyId,
        'project_type_id' => $projectType->id,
        'client_id' => $client->id,
        'pic_id' => $picId,
        'requestor_id' => $requestor?->id ?? $picId,
        'name' => 'Approval Notification Project',
        'status' => ProjectStatus::Draft,
    ]);
}

test('quotation approval dispatches approved notification to requester', function () {
    /** @var \Tests\TestCase $this */
    $estimate = Estimate::withoutEvents(fn () => Estimate::factory()
        ->withLineItems()
        ->create([
            'company_id' => $this->testCompany->id,
            'created_by' => $this->testUser->id,
            'updated_by' => $this->testUser->id,
        ]));

    $estimate->approveDraft();

    Queue::assertPushed(SendQuotationApprovedNotification::class, fn (SendQuotationApprovedNotification $job) => $job->estimateId === $estimate->id);

    (new SendQuotationApprovedNotification($estimate->id))->handle();

    Mail::assertSent(QuotationApprovedNotification::class, fn (QuotationApprovedNotification $mail) => $mail->hasTo($this->testUser->email)
        && $mail->estimate->is($estimate)
        && str_contains($mail->viewUrl(), '/company/' . $estimate->company_id . '/sales/estimates/' . $estimate->id));
});

test('duplicate quotation approval does not dispatch another notification job', function () {
    /** @var \Tests\TestCase $this */
    $estimate = Estimate::withoutEvents(fn () => Estimate::factory()
        ->withLineItems()
        ->create([
            'company_id' => $this->testCompany->id,
            'created_by' => $this->testUser->id,
        ]));

    $estimate->approveDraft();

    expect(fn () => $estimate->approveDraft())->toThrow(RuntimeException::class);

    Queue::assertPushed(SendQuotationApprovedNotification::class, 1);
});

test('project approval notification is sent to requestor', function () {
    /** @var \Tests\TestCase $this */
    $requestor = User::factory()->create([
        'email' => 'requestor-project@example.com',
    ]);

    $project = createDraftProjectForApprovalTests($this->testCompany->id, $this->testUser->id, $requestor);
    $project->update([
        'number' => 'KG26-999',
        'status' => ProjectStatus::Approved,
    ]);

    (new SendProjectApprovedNotification($project->id))->handle();

    Mail::assertSent(ProjectApprovedNotification::class, fn (ProjectApprovedNotification $mail) => $mail->hasTo($requestor->email)
        && $mail->project->is($project)
        && str_contains($mail->viewUrl(), '/company/' . $project->company_id . '/addons/projects/' . $project->id));
});

test('invoice first approval does not dispatch final approved notification', function () {
    /** @var \Tests\TestCase $this */
    ensureInvoiceAccountDefaultsForApprovalTests($this->testCompany->id);

    $invoice = Invoice::withoutEvents(fn () => Invoice::factory()
        ->withLineItems()
        ->create([
            'company_id' => $this->testCompany->id,
            'created_by' => $this->testUser->id,
            'updated_by' => $this->testUser->id,
        ]));

    $invoice->approveDraft();

    Queue::assertNotPushed(SendInvoiceApprovedNotification::class);
});

test('invoice final approval dispatches approved notification to requester', function () {
    /** @var \Tests\TestCase $this */
    $this->testUser->forceFill(['is_super_admin' => true])->save();
    ensureInvoiceAccountDefaultsForApprovalTests($this->testCompany->id);

    $taxCode = TaxCode::query()->create([
        'code' => '01',
        'description' => 'Test tax code',
    ]);

    $invoice = Invoice::withoutEvents(fn () => Invoice::factory()
        ->withLineItems()
        ->create([
            'company_id' => $this->testCompany->id,
            'created_by' => $this->testUser->id,
            'updated_by' => $this->testUser->id,
            'tax_code_id' => $taxCode->id,
            'tax_number' => 'TAX-001',
            'tax_date' => now()->toDateString(),
        ]));

    $invoice->approveDraft();

    Queue::fake();

    $invoice->finalizeInvoice();

    Queue::assertPushed(SendInvoiceApprovedNotification::class, fn (SendInvoiceApprovedNotification $job) => $job->invoiceId === $invoice->id);

    (new SendInvoiceApprovedNotification($invoice->id))->handle();

    Mail::assertSent(InvoiceApprovedNotification::class, fn (InvoiceApprovedNotification $mail) => $mail->hasTo($this->testUser->email)
        && $mail->invoice->is($invoice)
        && str_contains($mail->viewUrl(), '/company/' . $invoice->company_id . '/sales/invoices/' . $invoice->id));
});

test('duplicate invoice finalization does not dispatch another notification job', function () {
    /** @var \Tests\TestCase $this */
    $this->testUser->forceFill(['is_super_admin' => true])->save();
    ensureInvoiceAccountDefaultsForApprovalTests($this->testCompany->id);

    $taxCode = TaxCode::query()->create([
        'code' => '02',
        'description' => 'Test tax code 2',
    ]);

    $invoice = Invoice::withoutEvents(fn () => Invoice::factory()
        ->withLineItems()
        ->create([
            'company_id' => $this->testCompany->id,
            'created_by' => $this->testUser->id,
            'tax_code_id' => $taxCode->id,
            'tax_number' => 'TAX-002',
            'tax_date' => now()->toDateString(),
        ]));

    $invoice->approveDraft();
    $invoice->finalizeInvoice();

    expect(fn () => $invoice->finalizeInvoice())->toThrow(RuntimeException::class);

    Queue::assertPushed(SendInvoiceApprovedNotification::class, 1);
});

test('missing recipient email does not break quotation approval and logs skip', function () {
    /** @var \Tests\TestCase $this */
    $requestor = User::factory()->create([
        'email' => '',
    ]);

    $estimate = Estimate::withoutEvents(fn () => Estimate::factory()
        ->withLineItems()
        ->create([
            'company_id' => $this->testCompany->id,
            'created_by' => $requestor->id,
            'updated_by' => $requestor->id,
        ]));

    $estimate->approveDraft();

    expect($estimate->fresh()->wasApproved())->toBeTrue();

    (new SendQuotationApprovedNotification($estimate->id))->handle();

    Mail::assertNothingSent();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'SendQuotationApprovedNotification: notification email skipped.'
            && ($context['document_type'] ?? null) === 'quotation'
            && ($context['reason'] ?? null) === 'requestor_email_missing');
});

test('approval notification job does not send when document is not in approved state', function () {
    /** @var \Tests\TestCase $this */
    $estimate = Estimate::withoutEvents(fn () => Estimate::factory()
        ->withLineItems()
        ->create([
            'company_id' => $this->testCompany->id,
            'created_by' => $this->testUser->id,
        ]));

    (new SendQuotationApprovedNotification($estimate->id))->handle();

    Mail::assertNothingSent();

    $invoice = Invoice::withoutEvents(fn () => Invoice::factory()
        ->withLineItems()
        ->create([
            'company_id' => $this->testCompany->id,
            'created_by' => $this->testUser->id,
            'status' => InvoiceStatus::Draft,
        ]));

    (new SendInvoiceApprovedNotification($invoice->id))->handle();

    Mail::assertNothingSent();
});
