<?php

namespace App\Services;

use App\Enums\Accounting\AdjustmentCategory;
use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\AdjustmentStatus;
use App\Enums\Accounting\AdjustmentType;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Accounting\PaymentMethod;
use App\Enums\Addons\ProjectStatus;
use App\Models\Accounting\Account;
use App\Models\Accounting\Adjustment;
use App\Models\Accounting\Invoice;
use App\Models\Addons\Project;
use App\Models\Addons\ProjectType;
use App\Models\Banking\BankAccount;
use App\Models\Common\Client;
use App\Models\Common\Offering;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class LegacyProjectInvoiceImportService
{
    protected array $summary = [
        'projects' => [
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'stubbed' => 0,
        ],
        'invoices' => [
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'with_total' => 0,
            'paid_with_payment' => 0,
            'zero_total_rejected' => 0,
        ],
        'warnings' => [],
    ];

    protected bool $forceReimport = false;

    protected bool $preferSupplementFinancials = false;

    protected array $invoiceSupplementRows = [];

    protected array $projectReferenceMap = [];

    protected array $workBasisMap = [];

    protected array $clientCache = [];

    protected array $picCache = [];

    protected ?Offering $defaultOffering = null;

    protected ?BankAccount $activeBankAccount = null;

    protected ?LegacyWorkbookLayout $layout = null;

    /** @var array<int, ProjectType>|null */
    protected ?array $companyProjectTypes = null;

    protected int $stubSequence = 0;

    public function __construct(
        protected int $companyId,
        protected int $actorId,
    ) {
        $this->warmProjectReferenceMap();
    }

    public function summary(): array
    {
        return $this->summary;
    }

    public function setForceReimport(bool $forceReimport): self
    {
        $this->forceReimport = $forceReimport;

        return $this;
    }

    public function setPreferSupplementFinancials(bool $preferSupplementFinancials): self
    {
        $this->preferSupplementFinancials = $preferSupplementFinancials;

        return $this;
    }

    public function setWorkbookLayout(LegacyWorkbookLayout $layout): self
    {
        $this->layout = $layout;

        return $this;
    }

    protected function layout(): LegacyWorkbookLayout
    {
        return $this->layout ?? LegacyWorkbookLayout::detect([], []);
    }

    public function prepareImportedInvoiceForReimport(Invoice $invoice): void
    {
        if (! $invoice->is_imported) {
            throw new \RuntimeException("Invoice '{$invoice->invoice_number}' bukan data imported, tidak bisa di-reset untuk re-import.");
        }

        $this->resetImportedInvoice($invoice);
    }

    public function rememberInvoiceSupplementRows(array $rows): void
    {
        foreach ($rows as $row) {
            $mapped = $this->mapSupplementInvoiceRow($row['values'] ?? []);

            if (! $mapped || empty($mapped['invoice_number'])) {
                continue;
            }

            $this->invoiceSupplementRows[$mapped['invoice_number']] = $mapped;
        }
    }

    public function importProjectSheet(string $sheetName, array $rows): void
    {
        $projectTypeName = trim((string) str($sheetName)->after('Project-'));
        $projectType = $this->resolveProjectTypeForSheet($projectTypeName, $rows);

        if (! $projectType) {
            $this->addWarning("Sheet {$sheetName} dilewati karena project type '{$projectTypeName}' tidak ditemukan.");
            $this->summary['projects']['failed'] += count($rows);

            return;
        }

        $lastClientId = null;
        $lastClientName = null;

        foreach ($rows as $row) {
            $lineNumber = $row['row_number'] ?? null;
            $mapped = $this->mapProjectRow($row['values'] ?? []);

            if (! $mapped) {
                continue;
            }

            if (empty($mapped['number'])) {
                $this->summary['projects']['skipped']++;
                $this->addWarning("Baris {$lineNumber} pada {$sheetName} dilewati karena nomor project kosong.");

                continue;
            }

            try {
                $client = $this->resolveClient(
                    $mapped['client_name'],
                    $lastClientId,
                    $lastClientName,
                    $sheetName,
                    $lineNumber
                );

                if (! $client) {
                    $this->summary['projects']['failed']++;

                    continue;
                }

                [$clientId, $clientName] = $client;
                $lastClientId = $clientId;
                $lastClientName = $clientName;

                $projectName = $mapped['name'] ?: $this->buildLegacyProjectName($mapped['number']);

                $legacyTimestamp = $this->legacyCreatedAt(
                    $mapped['request_number_date'],
                    $mapped['start_date'],
                    $mapped['end_date'],
                );

                $attributes = [
                    'company_id' => $this->companyId,
                    'project_type_id' => $projectType->id,
                    'client_id' => $clientId,
                    'pic_id' => $this->resolvePicId($mapped['pic_internal']),
                    'number' => $mapped['number'],
                    'name' => $projectName,
                    'description' => $mapped['description'],
                    'request_number_date' => $mapped['request_number_date'],
                    'project_value' => $mapped['project_value'],
                    'start_date' => $mapped['start_date'],
                    'end_date' => $mapped['end_date'],
                    'work_basis' => $mapped['work_basis'],
                    'status' => ProjectStatus::Approved,
                    'pic_client_name' => $mapped['pic_client_name'],
                    'updated_at' => $legacyTimestamp,
                    'is_imported' => true,
                ];

                $existing = Project::query()
                    ->where('company_id', $this->companyId)
                    ->where('number', $mapped['number'])
                    ->first();

                if ($existing && ! $existing->is_imported) {
                    $this->summary['projects']['skipped']++;
                    $this->registerProjectReference($existing);
                    $this->addWarning("Project '{$mapped['number']}' sudah ada sebagai data non-imported, sehingga tidak dioverwrite.");

                    continue;
                }

                if ($existing) {
                    $existing->forceFill($attributes);
                    $existing->saveQuietly();
                    $project = $existing;
                    $this->summary['projects']['updated']++;
                } else {
                    $project = new Project();
                    $project->forceFill([
                        ...$attributes,
                        'created_at' => $legacyTimestamp,
                    ]);
                    $project->saveQuietly();
                    $this->summary['projects']['inserted']++;
                }

                $this->registerProjectReference($project);
            } catch (Throwable $e) {
                $this->summary['projects']['failed']++;
                $this->addWarning("Gagal mengimpor project pada {$sheetName} baris {$lineNumber}: {$e->getMessage()}");
                Log::warning('Legacy project import failed', [
                    'company_id' => $this->companyId,
                    'sheet' => $sheetName,
                    'row_number' => $lineNumber,
                    'exception' => $e,
                ]);
            }
        }
    }

    public function previewMapInvoiceRow(array $values): ?array
    {
        return $this->mapInvoiceRow($values);
    }

    public function importInvoiceSheet(array $rows): void
    {
        $rowsByInvoice = [];

        foreach ($rows as $row) {
            $mapped = $this->mapInvoiceRow($row['values'] ?? []);

            if (! $mapped || empty($mapped['invoice_number'])) {
                continue;
            }

            $invoiceNumber = $mapped['invoice_number'];

            if (
                ! isset($rowsByInvoice[$invoiceNumber])
                || $this->isBetterInvoiceImportRow($mapped, $rowsByInvoice[$invoiceNumber]['mapped'])
            ) {
                $rowsByInvoice[$invoiceNumber] = [
                    'row' => $row,
                    'mapped' => $mapped,
                ];
            }
        }

        foreach ($rowsByInvoice as ['row' => $row, 'mapped' => $mapped]) {
            $lineNumber = $row['row_number'] ?? null;

            try {
                $project = $this->resolveProjectForInvoice($mapped['project_reference'], $mapped['date']);

                if (! $project) {
                    $project = $this->createLegacyProjectStubForInvoice($mapped);

                    if (! $project) {
                        $this->summary['invoices']['failed']++;
                        $this->addWarning("Invoice '{$mapped['invoice_number']}' dilewati karena project '{$mapped['project_reference']}' tidak ditemukan dan gagal dibuat otomatis.");

                        continue;
                    }

                    $this->addWarning("Project '{$mapped['project_reference']}' tidak ditemukan, dibuat otomatis sebagai stub legacy untuk invoice '{$mapped['invoice_number']}'.");
                }

                $existing = Invoice::query()
                    ->where('company_id', $this->companyId)
                    ->where('invoice_number', $mapped['invoice_number'])
                    ->first();

                if ($existing && ! $existing->is_imported) {
                    $this->summary['invoices']['skipped']++;
                    $this->addWarning("Invoice '{$mapped['invoice_number']}' sudah ada sebagai data non-imported, sehingga tidak dioverwrite.");

                    continue;
                }

                if ($existing && $existing->payments()->exists() && ! $existing->is_imported && ! $this->forceReimport) {
                    $this->summary['invoices']['skipped']++;
                    $this->addWarning("Invoice '{$mapped['invoice_number']}' sudah ada sebagai data non-imported dengan payment, sehingga tidak dioverwrite.");

                    continue;
                }

                if ($mapped['total'] <= 0 && $mapped['status'] !== InvoiceStatus::Cancelled) {
                    $this->summary['invoices']['zero_total_rejected']++;
                    $this->summary['invoices']['failed']++;
                    $this->addWarning("Invoice '{$mapped['invoice_number']}' baris {$lineNumber} dilewati karena total 0 dan status bukan BATAL.");

                    continue;
                }

                if (empty($mapped['date'])) {
                    $mapped['date'] = $project->start_date
                        ?? $project->request_number_date
                        ?? $project->end_date
                        ?? now()->toDateString();
                    $mapped['due_date'] ??= $mapped['date'];
                }

                DB::transaction(function () use ($existing, $mapped, $project) {
                    $invoice = $existing ?? new Invoice();

                    if ($existing) {
                        $this->resetImportedInvoice($invoice);
                    }

                    $this->persistImportedInvoice($invoice, $mapped, $project);

                    if ($existing) {
                        $this->summary['invoices']['updated']++;
                    } else {
                        $this->summary['invoices']['inserted']++;
                    }

                    $invoice->refresh();

                    if ($invoice->total > 0) {
                        $this->summary['invoices']['with_total']++;
                    }

                    if ($invoice->status === InvoiceStatus::Paid && $invoice->payments()->exists()) {
                        $this->summary['invoices']['paid_with_payment']++;
                    }
                });
            } catch (Throwable $e) {
                $this->summary['invoices']['failed']++;
                $this->addWarning("Gagal mengimpor invoice pada baris {$lineNumber}: {$e->getMessage()}");
                Log::warning('Legacy invoice import failed', [
                    'company_id' => $this->companyId,
                    'row_number' => $lineNumber,
                    'invoice_number' => $mapped['invoice_number'] ?? null,
                    'exception' => $e,
                ]);
            }
        }
    }

    protected function persistImportedInvoice(Invoice $invoice, array $data, Project $project): void
    {
        $client = Client::query()->findOrFail($project->client_id);
        $legacyTimestamp = $this->legacyCreatedAt($data['date'], $data['due_date']);

        $attributes = [
            'company_id' => $this->companyId,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'invoice_number' => $data['invoice_number'],
            'date' => $data['date'],
            'due_date' => $data['due_date'] ?? $data['date'],
            'status' => InvoiceStatus::Draft,
            'currency_code' => $client->currency_code ?: 'IDR',
            'discount_method' => DocumentDiscountMethod::PerLineItem,
            'discount_total' => 0,
            'profit_computation' => AdjustmentComputation::Percentage,
            'profit_rate' => $data['profit_rate'],
            'profit_total' => $data['profit_total'],
            'subtotal' => $data['subtotal'],
            'tax_total' => $data['tax_total'],
            'total' => $data['total'],
            'actual_total' => $data['total'],
            'amount_paid' => 0,
            'tax_number' => $data['tax_number'],
            'tax_date' => $data['tax_number'] ? $data['date'] : null,
            'pph23' => $data['pph23'],
            'hide_offering_items' => true,
            'created_by' => $invoice->exists ? ($invoice->created_by ?: $this->actorId) : $this->actorId,
            'updated_by' => $this->actorId,
            'updated_at' => $legacyTimestamp,
            'is_imported' => true,
        ];

        if (! $invoice->exists) {
            $attributes['created_at'] = $legacyTimestamp;
        }

        $invoice->forceFill($attributes);
        $invoice->saveQuietly();

        $lineItem = $invoice->lineItems()->create([
            'company_id' => $this->companyId,
            'offering_id' => $this->resolveDefaultOffering()->id,
            'description' => $data['item_description'] ?: $project->name,
            'quantity' => 1,
            'unit' => 'lot',
            'unit_price' => $data['subtotal'],
            'tax_total' => 0,
            'discount_total' => 0,
            'line_number' => 1,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
            'created_at' => $legacyTimestamp,
            'updated_at' => $legacyTimestamp,
        ]);

        $taxAdjustment = $this->resolveSalesTaxAdjustment($data['subtotal'], $data['profit_total'], $data['tax_total'], $data['total']);

        if ($taxAdjustment) {
            $lineItem->refresh();
            $lineItem->salesTaxes()->sync([$taxAdjustment->id]);
            $lineItem->updateQuietly([
                'tax_total' => $lineItem->calculateTaxTotalAmount(),
                'updated_at' => $legacyTimestamp,
            ]);
        }

        $invoice->load(['lineItems.adjustments', 'lineItems.offering', 'client']);
        $invoice->forceFill([
            'subtotal' => $data['subtotal'],
            'profit_total' => $data['profit_total'],
            'profit_rate' => $data['profit_rate'],
            'tax_total' => $data['tax_total'],
            'total' => $data['total'],
            'pph23' => $data['pph23'],
            'updated_by' => $this->actorId,
            'updated_at' => $legacyTimestamp,
            'is_imported' => true,
        ])->save();

        $invoice->refresh();

        $finalStatus = $data['status'];

        if ($finalStatus === InvoiceStatus::Draft) {
            $invoice->forceFill([
                'status' => InvoiceStatus::Draft,
                'approved_at' => null,
                'last_sent_at' => null,
                'paid_at' => null,
                'amount_paid' => 0,
                'updated_by' => $this->actorId,
                'updated_at' => $legacyTimestamp,
                'is_imported' => true,
            ])->saveQuietly();
            $this->syncInvoiceUpdatedAt($invoice, $legacyTimestamp);

            return;
        }

        $invoice->approveDraft($this->dateTimeOrNow($data['date']));

        if (in_array($finalStatus, [InvoiceStatus::Sent, InvoiceStatus::Overdue, InvoiceStatus::Viewed, InvoiceStatus::Paid, InvoiceStatus::Partial, InvoiceStatus::Cancelled], true)) {
            $invoice->markAsSent($this->dateTimeOrNow($data['date']));
            $invoice->refresh();
        }

        if ($finalStatus === InvoiceStatus::Cancelled) {
            $invoice->cancelWithAccountingVoid();
            $invoice->forceFill([
                'is_imported' => true,
                'updated_by' => $this->actorId,
                'updated_at' => $legacyTimestamp,
            ])->saveQuietly();

            return;
        }

        if ($finalStatus === InvoiceStatus::Paid) {
            $paidAmount = $data['paid_value'] > 0 ? $data['paid_value'] : (int) $invoice->actual_total;
            $this->recordImportedPaidPayment(
                invoice: $invoice,
                amount: $paidAmount,
                postedAt: $data['date'],
                pph23: $data['pph23'],
            );
            $this->syncInvoiceUpdatedAt($invoice, $legacyTimestamp);

            return;
        }

        if ($finalStatus === InvoiceStatus::Partial) {
            $amountPaid = min($data['paid_value'], (int) $invoice->actual_total);
            $invoice->forceFill([
                'status' => InvoiceStatus::Partial,
                'amount_paid' => $amountPaid,
                'updated_by' => $this->actorId,
                'updated_at' => $legacyTimestamp,
                'is_imported' => true,
            ])->saveQuietly();

            return;
        }

        if ($finalStatus === InvoiceStatus::Viewed) {
            $invoice->markAsViewed($this->dateTimeOrNow($data['date']));
            $this->syncInvoiceUpdatedAt($invoice, $legacyTimestamp);

            return;
        }

        if ($finalStatus === InvoiceStatus::Sent) {
            $invoice->forceFill([
                'updated_by' => $this->actorId,
                'updated_at' => $legacyTimestamp,
                'is_imported' => true,
            ])->saveQuietly();
        }

        $this->syncInvoiceUpdatedAt($invoice, $legacyTimestamp);
    }

    protected function resetImportedInvoice(Invoice $invoice): void
    {
        $invoice->transactions()->get()->each(function ($transaction): void {
            $transaction->delete();
        });

        $invoice->lineItems()->get()->each(function ($lineItem): void {
            $lineItem->delete();
        });

        $invoice->forceFill([
            'status' => InvoiceStatus::Draft,
            'approved_at' => null,
            'paid_at' => null,
            'last_sent_at' => null,
            'last_viewed_at' => null,
            'amount_paid' => 0,
            'tax_number' => null,
            'tax_date' => null,
            'pph23' => null,
            'updated_by' => $this->actorId,
            'is_imported' => true,
        ])->saveQuietly();
    }

    protected function mapProjectRow(array $values): ?array
    {
        if ($this->isEmptyRow($values)) {
            return null;
        }

        $name = $this->cleanText($values[0] ?? null);
        $number = $this->buildProjectNumber(
            $values[2] ?? null,
            $values[4] ?? null,
        );

        if (! $name && ! $number) {
            return null;
        }

        return [
            'name' => $name,
            'request_number_date' => $this->parseDate($values[1] ?? null),
            'number' => $number,
            'work_basis' => $this->cleanText($values[5] ?? null),
            'project_value' => $this->parseMoneyToMinor($values[6] ?? null),
            'start_date' => $this->parseDate($values[7] ?? null),
            'end_date' => $this->parseDate($values[8] ?? null),
            'client_name' => $this->cleanText($values[9] ?? null),
            'pic_client_name' => $this->cleanText($values[10] ?? null),
            'pic_internal' => $this->cleanText($values[11] ?? null),
            'description' => $this->cleanText($values[12] ?? null),
        ];
    }

    protected function isBetterInvoiceImportRow(array $candidate, array $current): bool
    {
        $candidateScore = $this->invoiceImportRowQualityScore($candidate);
        $currentScore = $this->invoiceImportRowQualityScore($current);

        if ($candidateScore !== $currentScore) {
            return $candidateScore > $currentScore;
        }

        return ($candidate['total'] ?? 0) >= ($current['total'] ?? 0);
    }

    protected function invoiceImportRowQualityScore(array $mapped): int
    {
        $score = 0;

        if ($this->cleanText($mapped['project_reference'] ?? null)) {
            $score += 100;
        }

        if (($mapped['total'] ?? 0) > 0) {
            $score += 50;
        }

        if ($this->cleanText($mapped['item_description'] ?? null)) {
            $score += 10;
        }

        if (! empty($mapped['date'])) {
            $score += 5;
        }

        if (($mapped['paid_value'] ?? 0) > 0) {
            $score += 5;
        }

        if (($mapped['status'] ?? null) === InvoiceStatus::Paid) {
            $score += 1;
        }

        return $score;
    }

    protected function mapInvoiceRow(array $values): ?array
    {
        if ($this->isEmptyRow($values)) {
            return null;
        }

        $layout = $this->layout();
        $invoiceNumber = $this->normalizeDocumentNumber($layout->valueAt($values, 'invoice', 'invoice_number'));

        if (! $invoiceNumber) {
            return null;
        }

        $supplement = $this->invoiceSupplementRows[$invoiceNumber] ?? [];
        $hasSupplementFinancials = $this->supplementHasFinancialData($supplement);
        $mainProjectReference = $this->cleanText($layout->valueAt($values, 'invoice', 'project_reference'));
        $mainDescription = $this->cleanText($layout->valueAt($values, 'invoice', 'item_description'));

        $invoicePreTax = $this->deriveInvoicePretaxTotal($values);
        $invoiceTax = $this->parseMoneyToMinor($layout->valueAt($values, 'invoice', 'tax_total'));
        $invoiceTotal = $this->parseMoneyToMinor($layout->valueAt($values, 'invoice', 'total'));

        if ($invoiceTotal <= 0 && $invoicePreTax > 0 && $invoiceTax > 0) {
            $invoiceTotal = $invoicePreTax + $invoiceTax;
        }

        $invoicePaid = $this->parseMoneyToMinor($layout->valueAt($values, 'invoice', 'paid_value'));
        $supplementPreTax = $hasSupplementFinancials ? (int) ($supplement['pre_tax_total'] ?? 0) : 0;
        $supplementTax = $hasSupplementFinancials ? (int) ($supplement['tax_total'] ?? 0) : 0;
        $supplementTotal = $hasSupplementFinancials ? (int) ($supplement['total'] ?? 0) : 0;
        $supplementPaid = $hasSupplementFinancials ? (int) ($supplement['paid_value'] ?? 0) : 0;
        $derivedInvoiceTotal = $invoiceTotal > 0 ? $invoiceTotal : max($invoicePreTax + $invoiceTax, 0);

        $preTaxTotal = $this->preferFinancialAmount($invoicePreTax, $supplementPreTax, $hasSupplementFinancials);
        $taxTotal = $this->preferFinancialAmount($invoiceTax, $supplementTax, $hasSupplementFinancials);
        $total = $this->preferFinancialAmount($derivedInvoiceTotal, $supplementTotal, $hasSupplementFinancials);
        $paidValue = $this->preferSupplementFinancials
            ? $this->firstPositive($supplementPaid, $invoicePaid)
            : $invoicePaid;

        [$subtotal, $profitTotal] = $this->deriveSubtotalAndProfit(
            supplement: $supplement,
            hasSupplementFinancials: $hasSupplementFinancials,
            values: $values,
            preTaxTotal: $preTaxTotal,
        );

        $status = $this->normalizeInvoiceStatus(
            $this->resolveImportStatus($supplement, $values, $hasSupplementFinancials),
            $paidValue,
        );

        $profitRate = $subtotal > 0 && $profitTotal > 0
            ? (int) round(($profitTotal / $subtotal) * 100 * 10000)
            : 0;

        $projectReference = $mainProjectReference
            ?: $this->cleanText($supplement['project_reference'] ?? null)
            ?: $this->cleanText($supplement['contract'] ?? null);

        return [
            'invoice_number' => $invoiceNumber,
            'date' => $this->parseDate($supplement['date'] ?? null)
                ?? $this->parseDate($layout->valueAt($values, 'invoice', 'date')),
            'due_date' => $this->parseDate($supplement['due_date'] ?? null)
                ?? $this->parseDate($supplement['date'] ?? null)
                ?? $this->parseDate($layout->valueAt($values, 'invoice', 'date')),
            'project_reference' => $projectReference,
            'item_description' => $mainDescription ?: $this->cleanText($supplement['item_description'] ?? null),
            'subtotal' => $subtotal,
            'profit_total' => $profitTotal,
            'profit_rate' => $profitRate,
            'tax_total' => $taxTotal,
            'total' => $total,
            'status' => $status,
            'paid_value' => $paidValue,
            'tax_number' => $this->cleanText($supplement['tax_number'] ?? null)
                ?: $this->cleanText($layout->valueAt($values, 'invoice', 'tax_number')),
            'pekerjaan_hint' => $this->cleanText($supplement['pekerjaan'] ?? null),
            'pph23' => $this->deriveImportedPph23(
                status: $status,
                preTaxTotal: $preTaxTotal,
                paidValue: $paidValue,
                subtotal: $subtotal,
                profitTotal: $profitTotal,
                total: $total,
            ),
        ];
    }

    protected function deriveImportedPph23(
        InvoiceStatus $status,
        int $preTaxTotal,
        int $paidValue,
        int $subtotal,
        int $profitTotal,
        int $total,
    ): ?int {
        if ($status !== InvoiceStatus::Paid) {
            return null;
        }

        if ($paidValue > 0 && $preTaxTotal > $paidValue) {
            return $preTaxTotal - $paidValue;
        }

        if (! $this->legacyImportInvoiceQualifiesAsWapu($total)) {
            return null;
        }

        $base = $subtotal + $profitTotal;

        if ($base <= 0) {
            return null;
        }

        return (int) round($base * 0.02);
    }

    protected function legacyImportInvoiceQualifiesAsWapu(int $total): bool
    {
        return $total > 1_000_000_000
            && Adjustment::companyHasPpnWapu($this->companyId);
    }

    protected function recordImportedPaidPayment(Invoice $invoice, int $amount, ?string $postedAt, ?int $pph23): void
    {
        if ($amount <= 0) {
            return;
        }

        if ($pph23 !== null && (int) $invoice->pph23 !== $pph23) {
            $invoice->forceFill([
                'pph23' => $pph23,
                'updated_by' => $this->actorId,
                'updated_at' => $this->dateTimeOrNow($postedAt),
                'is_imported' => true,
            ])->saveQuietly();
        }

        app(LegacyImportedInvoiceJournalService::class)
            ->preparePaidInvoiceForLegacyImport($invoice);

        $invoice->forceFill([
            'actual_total' => $amount,
            'updated_by' => $this->actorId,
            'updated_at' => $this->dateTimeOrNow($postedAt),
            'is_imported' => true,
        ])->saveQuietly();

        $bankAccount = $this->resolveActiveBankAccount();

        $invoice->recordPayment([
            'posted_at' => $postedAt ?? now()->toDateString(),
            'payment_method' => PaymentMethod::BankPayment,
            'bank_account_id' => $bankAccount->id,
            'amount' => $amount,
            'pph23' => $pph23,
            'notes' => 'Legacy import payment backfill',
        ]);

        $invoice->refresh();
        $invoice->forceFill([
            'status' => InvoiceStatus::Paid,
            'actual_total' => $amount,
            'amount_paid' => $amount,
            'paid_at' => $this->dateTimeOrNow($postedAt),
            'updated_by' => $this->actorId,
            'updated_at' => $this->dateTimeOrNow($postedAt),
            'is_imported' => true,
        ])->saveQuietly();
    }

    protected function mapSupplementInvoiceRow(array $values): ?array
    {
        if ($this->isEmptyRow($values)) {
            return null;
        }

        $layout = $this->layout();
        $invoiceNumber = $this->normalizeDocumentNumber(
            ($layout->valueAt($values, 'supplement', 'invoice_number') ?: null)
            ?: ($layout->valueAt($values, 'supplement', 'invoice_number_alt') ?: null)
        );

        if (! $invoiceNumber) {
            return null;
        }

        $nominalInvoice = $this->parseMoneyToMinor($layout->valueAt($values, 'supplement', 'nominal_invoice'));
        $baseOrProfit = $this->parseMoneyToMinor($layout->valueAt($values, 'supplement', 'profit'));
        $preTaxTotal = $this->parseMoneyToMinor(
            ($layout->valueAt($values, 'supplement', 'pre_tax_total') ?: null)
            ?: ($layout->valueAt($values, 'supplement', 'nominal_invoice') ?: null)
        );

        [$subtotal, $profitTotal] = $this->deriveSubtotalAndProfitFromSupplement(
            nominalInvoice: $nominalInvoice,
            baseOrProfit: $baseOrProfit,
            preTaxTotal: $preTaxTotal,
        );

        $contract = $this->cleanText($layout->valueAt($values, 'supplement', 'contract'));
        $projectReference = $this->cleanText($layout->valueAt($values, 'supplement', 'project_reference')) ?: $contract;

        return [
            'invoice_number' => $invoiceNumber,
            'date' => $this->parseDate($layout->valueAt($values, 'supplement', 'date')),
            'due_date' => $this->parseDate($layout->valueAt($values, 'supplement', 'due_date')),
            'contract' => $contract,
            'pekerjaan' => $this->cleanText($layout->valueAt($values, 'supplement', 'pekerjaan')),
            'project_reference' => $projectReference,
            'item_description' => $this->cleanText($layout->valueAt($values, 'supplement', 'item_description')),
            'pre_tax_total' => $preTaxTotal,
            'subtotal' => $subtotal,
            'profit_total' => $profitTotal,
            'tax_total' => $this->parseMoneyToMinor($layout->valueAt($values, 'supplement', 'tax_total')),
            'total' => $this->parseMoneyToMinor($layout->valueAt($values, 'supplement', 'total')),
            'status' => $this->cleanText($layout->valueAt($values, 'supplement', 'status')),
            'paid_value' => $this->parseMoneyToMinor($layout->valueAt($values, 'supplement', 'paid_value')),
            'tax_number' => $this->cleanText($layout->valueAt($values, 'supplement', 'tax_number')),
        ];
    }

    protected function deriveSubtotalAndProfitFromSupplement(int $nominalInvoice, int $baseOrProfit, int $preTaxTotal): array
    {
        if ($preTaxTotal <= 0) {
            return [0, 0];
        }

        if ($nominalInvoice > 0 && $nominalInvoice < $preTaxTotal) {
            return [$nominalInvoice, $preTaxTotal - $nominalInvoice];
        }

        if ($baseOrProfit > 0 && $baseOrProfit < $preTaxTotal) {
            return [$baseOrProfit, $preTaxTotal - $baseOrProfit];
        }

        return [$preTaxTotal, 0];
    }

    protected function deriveInvoicePretaxTotal(array $values): int
    {
        $layout = $this->layout();
        $total = $this->parseMoneyToMinor($layout->valueAt($values, 'invoice', 'total'));
        $tax = $this->parseMoneyToMinor($layout->valueAt($values, 'invoice', 'tax_total'));
        $profit = $this->parseMoneyToMinor($layout->valueAt($values, 'invoice', 'profit'));
        $dpp = $this->parseMoneyToMinor($layout->valueAt($values, 'invoice', 'dpp'));

        if ($total > 0 && $tax > 0) {
            return max($total - $tax, 0);
        }

        if ($dpp > 0 && $profit > 0) {
            return $dpp + $profit;
        }

        if ($profit > 0 && $total > 0) {
            return max($total - $tax, $profit);
        }

        return $this->firstPositive($dpp, $profit);
    }

    protected function deriveSubtotalAndProfit(
        array $supplement,
        bool $hasSupplementFinancials,
        array $values,
        int $preTaxTotal,
    ): array {
        $dpp = $this->parseMoneyToMinor($this->layout()->valueAt($values, 'invoice', 'dpp'));
        $profit = $this->parseMoneyToMinor($this->layout()->valueAt($values, 'invoice', 'profit'));

        if (! $this->preferSupplementFinancials) {
            if ($dpp > 0) {
                return [$dpp, $profit > 0 ? $profit : max($preTaxTotal - $dpp, 0)];
            }

            if ($profit > 0 && $preTaxTotal > 0) {
                return [max($preTaxTotal - $profit, 0), $profit];
            }
        }

        if ($hasSupplementFinancials && ($supplement['subtotal'] ?? 0) > 0) {
            return [
                $supplement['subtotal'],
                ($supplement['profit_total'] ?? 0) > 0
                    ? $supplement['profit_total']
                    : max($preTaxTotal - $supplement['subtotal'], 0),
            ];
        }

        if ($hasSupplementFinancials && ($supplement['profit_total'] ?? 0) > 0 && $preTaxTotal > 0) {
            return [
                max($preTaxTotal - $supplement['profit_total'], 0),
                $supplement['profit_total'],
            ];
        }

        if ($this->preferSupplementFinancials) {
            if ($dpp > 0) {
                return [$dpp, $profit > 0 ? $profit : max($preTaxTotal - $dpp, 0)];
            }

            if ($profit > 0 && $preTaxTotal > 0) {
                return [max($preTaxTotal - $profit, 0), $profit];
            }
        }

        return [$preTaxTotal, 0];
    }

    protected function supplementHasFinancialData(array $supplement): bool
    {
        if ($supplement === []) {
            return false;
        }

        return ($supplement['pre_tax_total'] ?? 0) > 0
            || ($supplement['subtotal'] ?? 0) > 0
            || ($supplement['profit_total'] ?? 0) > 0
            || ($supplement['tax_total'] ?? 0) > 0
            || ($supplement['total'] ?? 0) > 0
            || ($supplement['paid_value'] ?? 0) > 0;
    }

    protected function resolveImportStatus(array $supplement, array $values, bool $hasSupplementFinancials): mixed
    {
        $invoiceStatus = $this->layout()->valueAt($values, 'invoice', 'status');
        $supplementStatus = $supplement['status'] ?? null;

        if (! $this->preferSupplementFinancials) {
            if ($this->isMeaningfulStatus($invoiceStatus)) {
                return $invoiceStatus;
            }

            if ($this->isCancelledStatus($supplementStatus)) {
                return $supplementStatus;
            }

            return $invoiceStatus;
        }

        if ($hasSupplementFinancials && $this->isMeaningfulStatus($supplementStatus)) {
            return $supplementStatus;
        }

        if ($this->isMeaningfulStatus($invoiceStatus)) {
            return $invoiceStatus;
        }

        if (! $hasSupplementFinancials && $this->isCancelledStatus($supplementStatus)) {
            return $supplementStatus;
        }

        return $invoiceStatus ?? $supplementStatus;
    }

    protected function isMeaningfulStatus(mixed $status): bool
    {
        $normalized = strtoupper(trim((string) $status));

        return $normalized !== ''
            && ! in_array($normalized, ['#N/A', 'N/A', '#REF!', 'REF!', '0', '-', 'NULL'], true);
    }

    protected function isCancelledStatus(mixed $status): bool
    {
        return strtoupper(trim((string) $status)) === 'BATAL';
    }

    protected function preferFinancialAmount(int $invoiceAmount, int $supplementAmount, bool $hasSupplementFinancials): int
    {
        if ($this->preferSupplementFinancials && $hasSupplementFinancials) {
            return $this->firstPositive($supplementAmount, $invoiceAmount);
        }

        return $this->firstPositive($invoiceAmount, $supplementAmount);
    }

    protected function firstPositive(int ...$candidates): int
    {
        foreach ($candidates as $value) {
            if ($value > 0) {
                return $value;
            }
        }

        return 0;
    }

    protected function normalizeInvoiceStatus(mixed $rawStatus, int $paidValue): InvoiceStatus
    {
        $normalized = strtoupper(trim((string) $rawStatus));

        return match (true) {
            $normalized === 'BATAL' => InvoiceStatus::Cancelled,
            $normalized === 'PAID' => InvoiceStatus::Paid,
            $paidValue > 0 => InvoiceStatus::Partial,
            $this->isMeaningfulStatus($rawStatus) => InvoiceStatus::Sent,
            default => InvoiceStatus::Draft,
        };
    }

    protected function resolveClient(
        ?string $clientName,
        ?int $fallbackClientId,
        ?string $fallbackClientName,
        string $sheetName,
        ?int $lineNumber
    ): ?array {
        $clientName = $this->cleanText($clientName);

        if (! $clientName) {
            if ($fallbackClientId !== null) {
                return [$fallbackClientId, $fallbackClientName];
            }

            $this->addWarning("Baris {$lineNumber} pada {$sheetName} dilewati karena client kosong dan tidak ada client sebelumnya untuk diwariskan.");

            return null;
        }

        if (isset($this->clientCache[$clientName])) {
            return [$this->clientCache[$clientName]->id, $this->clientCache[$clientName]->name];
        }

        $client = Client::query()
            ->where('company_id', $this->companyId)
            ->whereRaw('LOWER(name) = ?', [strtolower($clientName)])
            ->first();

        if (! $client) {
            $client = Client::query()->create([
                'company_id' => $this->companyId,
                'name' => $clientName,
                'currency_code' => 'IDR',
                'created_by' => $this->actorId,
                'updated_by' => $this->actorId,
            ]);
        }

        $this->clientCache[$clientName] = $client;

        return [$client->id, $client->name];
    }

    protected function resolvePicId(?string $picName): int
    {
        $picName = $this->cleanText($picName);

        if (! $picName) {
            return $this->actorId;
        }

        if (array_key_exists($picName, $this->picCache)) {
            return $this->picCache[$picName];
        }

        $normalized = strtolower(str_replace('&', ' ', $picName));
        $words = array_values(array_filter(preg_split('/\s+/', $normalized) ?: []));
        $query = User::query()->where('company_id', $this->companyId);

        foreach ($words as $word) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%' . $word . '%']);
        }

        $user = $query
            ->orderByRaw('LENGTH(name)')
            ->first();

        if (! $user && $words !== []) {
            $user = User::query()
                ->where('company_id', $this->companyId)
                ->whereRaw('LOWER(name) LIKE ?', ['%' . $words[0] . '%'])
                ->orderByRaw('LENGTH(name)')
                ->first();
        }

        $this->picCache[$picName] = $user?->id ?? $this->actorId;

        return $this->picCache[$picName];
    }

    protected function resolveProjectForInvoice(?string $reference, ?string $invoiceDate): ?Project
    {
        $reference = $this->cleanText($reference);

        if (! $reference) {
            return null;
        }

        foreach ($this->projectReferenceAliases($reference, $invoiceDate) as $alias) {
            if (isset($this->projectReferenceMap[$alias])) {
                return $this->projectReferenceMap[$alias];
            }
        }

        foreach ($this->workBasisAliases($reference) as $alias) {
            if (isset($this->workBasisMap[$alias])) {
                return $this->workBasisMap[$alias];
            }
        }

        $query = Project::query()
            ->where('company_id', $this->companyId);

        $normalized = strtoupper(str_replace(["\n", "\r", ' '], '', $reference));

        if (preg_match('/^([A-Z]+)(\d{3})$/', $normalized, $matches)) {
            $prefix = $matches[1];
            $suffix = $matches[2];
            $year = $invoiceDate ? Carbon::parse($invoiceDate)->format('y') : null;

            $query->where(function ($subQuery) use ($prefix, $suffix, $year): void {
                if ($year) {
                    $subQuery->orWhere('number', "{$prefix}{$year}-{$suffix}");
                }

                $subQuery
                    ->orWhere('number', 'like', "{$prefix}%-{$suffix}")
                    ->orWhere('number', 'like', "{$prefix}{$suffix}");
            });
        } elseif (preg_match('/^([A-Z]+)(\d{2})-(\d{3})$/', $normalized, $matches)) {
            $prefix = $matches[1];
            $referenceYear = $matches[2];
            $suffix = $matches[3];
            $invoiceYear = $invoiceDate ? Carbon::parse($invoiceDate)->format('y') : null;

            $query->where(function ($subQuery) use ($prefix, $referenceYear, $suffix, $invoiceYear): void {
                $subQuery->orWhere('number', "{$prefix}{$referenceYear}-{$suffix}");

                if ($invoiceYear && $invoiceYear !== $referenceYear) {
                    $subQuery->orWhere('number', "{$prefix}{$invoiceYear}-{$suffix}");
                }

                $subQuery->orWhere('number', 'like', "{$prefix}%-{$suffix}");
            });
        } elseif ($this->looksLikeContractReference($reference)) {
            $query->where(function ($subQuery) use ($reference): void {
                $subQuery
                    ->orWhere('work_basis', $reference)
                    ->orWhere('work_basis', 'like', '%' . $reference . '%')
                    ->orWhere('number', $reference);
            });
        } else {
            $query->where(function ($subQuery) use ($reference): void {
                $subQuery
                    ->orWhere('number', $reference)
                    ->orWhere('work_basis', $reference);
            });
        }

        $project = $query->orderByDesc('id')->first();

        if ($project) {
            $this->registerProjectReference($project);
        }

        return $project;
    }

    protected function createLegacyProjectStubForInvoice(array $invoiceData): ?Project
    {
        $reference = $this->cleanText($invoiceData['project_reference'] ?? null);

        if (! $reference) {
            return null;
        }

        $projectType = $this->resolveProjectTypeByNameHint($invoiceData['pekerjaan_hint'] ?? null)
            ?? $this->resolveProjectTypeFromReference($reference);

        if (! $projectType) {
            return null;
        }

        $client = Client::query()
            ->where('company_id', $this->companyId)
            ->orderBy('id')
            ->first();

        if (! $client) {
            $client = Client::query()->create([
                'company_id' => $this->companyId,
                'name' => 'Legacy Import Client',
                'currency_code' => 'IDR',
                'created_by' => $this->actorId,
                'updated_by' => $this->actorId,
            ]);
        }

        $legacyTimestamp = $this->legacyCreatedAt($invoiceData['date'] ?? null);
        $description = $this->cleanText($invoiceData['item_description'] ?? null);
        $isContractReference = $this->looksLikeContractReference($reference);
        $projectNumber = $isContractReference
            ? $this->buildContractStubProjectNumber($projectType, $reference, $invoiceData['date'] ?? null)
            : strtoupper($reference);

        $project = new Project();
        $project->forceFill([
            'company_id' => $this->companyId,
            'project_type_id' => $projectType->id,
            'client_id' => $client->id,
            'pic_id' => $this->actorId,
            'number' => $projectNumber,
            'name' => $description ?: $this->buildLegacyProjectName($projectNumber),
            'description' => $description,
            'request_number_date' => $invoiceData['date'] ?? null,
            'start_date' => $invoiceData['date'] ?? null,
            'work_basis' => $isContractReference ? $reference : null,
            'status' => ProjectStatus::Approved,
            'is_imported' => true,
            'created_at' => $legacyTimestamp,
            'updated_at' => $legacyTimestamp,
        ]);
        $project->saveQuietly();

        $this->registerProjectReference($project);
        $this->summary['projects']['stubbed']++;

        return $project;
    }

    protected function resolveProjectTypeFromReference(string $reference): ?ProjectType
    {
        $reference = strtoupper(str_replace(["\n", "\r", ' '], '', $reference));

        if (preg_match('/^([A-Z]+)/', $reference, $matches)) {
            $type = $this->resolveProjectTypeByCodePrefix($matches[1]);

            if ($type) {
                return $type;
            }
        }

        if ($this->looksLikeContractReference($reference)) {
            return null;
        }

        return null;
    }

    protected function resolveProjectTypeForSheet(string $sheetSuffix, array $rows): ?ProjectType
    {
        $types = $this->getCompanyProjectTypes();

        foreach ($types as $type) {
            if (strcasecmp($type->name, $sheetSuffix) === 0) {
                return $type;
            }
        }

        foreach ($types as $type) {
            if (str_contains(strtolower($type->name), strtolower($sheetSuffix))
                || str_contains(strtolower($sheetSuffix), strtolower($type->name))) {
                return $type;
            }
        }

        $aliases = [
            'EO' => ['event organizer', 'eo'],
            'Pengadaan' => ['pengadaan barang', 'pengadaan'],
            'Survey' => ['survey'],
            'Tenaga Kerja' => ['tenaga kerja'],
        ];

        foreach ($aliases[$sheetSuffix] ?? [strtolower($sheetSuffix)] as $alias) {
            foreach ($types as $type) {
                if (str_contains(strtolower($type->name), $alias)) {
                    return $type;
                }
            }
        }

        foreach ($rows as $row) {
            $values = $row['values'] ?? [];
            $prefix = $this->cleanText($values[2] ?? null);
            $number = $this->buildProjectNumber($values[2] ?? null, $values[4] ?? null);

            foreach ([$prefix, $number] as $candidate) {
                if (! $candidate) {
                    continue;
                }

                $normalized = strtoupper(str_replace([' ', '-'], '', $candidate));

                if (preg_match('/^([A-Z]+)/', $normalized, $matches)) {
                    $type = $this->resolveProjectTypeByCodePrefix($matches[1]);

                    if ($type) {
                        return $type;
                    }
                }
            }
        }

        return count($types) === 1 ? $types[0] : null;
    }

    protected function resolveProjectTypeByNameHint(?string $hint): ?ProjectType
    {
        $hint = $this->cleanText($hint);

        if (! $hint) {
            return null;
        }

        $types = $this->getCompanyProjectTypes();
        $normalizedHint = strtolower($hint);

        foreach ($types as $type) {
            if (strtolower($type->name) === $normalizedHint) {
                return $type;
            }
        }

        foreach ($types as $type) {
            if (str_contains(strtolower($type->name), $normalizedHint)
                || str_contains($normalizedHint, strtolower($type->name))) {
                return $type;
            }
        }

        foreach ($types as $type) {
            if ($type->code && str_contains($normalizedHint, strtolower($type->code))) {
                return $type;
            }
        }

        return null;
    }

    protected function resolveProjectTypeByCodePrefix(string $prefix): ?ProjectType
    {
        $prefix = strtoupper($prefix);
        $types = $this->getCompanyProjectTypes();

        usort($types, fn (ProjectType $a, ProjectType $b) => strlen((string) $b->code) <=> strlen((string) $a->code));

        foreach ($types as $type) {
            $code = strtoupper((string) $type->code);

            if ($code !== '' && str_starts_with($prefix, $code)) {
                return $type;
            }
        }

        $legacyPrefixToTypeCode = [
            'PG' => 'EO',
        ];

        if (isset($legacyPrefixToTypeCode[$prefix])) {
            foreach ($types as $type) {
                if (strtoupper((string) $type->code) === $legacyPrefixToTypeCode[$prefix]) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, ProjectType>
     */
    protected function getCompanyProjectTypes(): array
    {
        if ($this->companyProjectTypes !== null) {
            return $this->companyProjectTypes;
        }

        $this->companyProjectTypes = ProjectType::query()
            ->where('company_id', $this->companyId)
            ->orderBy('id')
            ->get()
            ->all();

        return $this->companyProjectTypes;
    }

    protected function buildContractStubProjectNumber(ProjectType $projectType, string $contract, ?string $invoiceDate): string
    {
        $existing = Project::query()
            ->where('company_id', $this->companyId)
            ->where('work_basis', $contract)
            ->value('number');

        if ($existing) {
            return $existing;
        }

        $existingByNumber = Project::query()
            ->where('company_id', $this->companyId)
            ->where('number', $contract)
            ->exists();

        if (! $existingByNumber) {
            return $contract;
        }

        $year = $invoiceDate ? Carbon::parse($invoiceDate)->format('y') : now()->format('y');
        $code = strtoupper((string) ($projectType->code ?: 'LEG'));
        $this->stubSequence++;

        return sprintf('%s%s-STB%03d', $code, $year, $this->stubSequence);
    }

    protected function looksLikeContractReference(string $reference): bool
    {
        $reference = trim($reference);

        return $reference !== ''
            && preg_match('/^\d{6,}$/', $reference);
    }

    protected function workBasisAliases(string $reference): array
    {
        $reference = trim($reference);

        if ($reference === '') {
            return [];
        }

        return array_values(array_unique([
            $reference,
            preg_replace('/\D/', '', $reference) ?? $reference,
        ]));
    }

    protected function resolveDefaultOffering(): Offering
    {
        if ($this->defaultOffering) {
            return $this->defaultOffering;
        }

        $this->defaultOffering = Offering::query()
            ->where('company_id', $this->companyId)
            ->where('name', 'Pekerjaan')
            ->where('sellable', true)
            ->whereNotNull('income_account_id')
            ->first();

        if (! $this->defaultOffering) {
            $this->defaultOffering = Offering::query()
                ->where('company_id', $this->companyId)
                ->where('sellable', true)
                ->whereNotNull('income_account_id')
                ->orderBy('id')
                ->first();
        }

        if (! $this->defaultOffering) {
            throw new \RuntimeException('Tidak ada offering sellable dengan income account yang bisa dipakai untuk import invoice legacy.');
        }

        return $this->defaultOffering;
    }

    protected function resolveActiveBankAccount(): BankAccount
    {
        if ($this->activeBankAccount) {
            return $this->activeBankAccount;
        }

        $this->activeBankAccount = BankAccount::query()
            ->where('company_id', $this->companyId)
            ->where('enabled', true)
            ->first();

        if (! $this->activeBankAccount) {
            throw new \RuntimeException('Tidak ada bank account aktif untuk company ini. Aktifkan satu bank account terlebih dahulu sebelum import invoice paid.');
        }

        return $this->activeBankAccount;
    }

    protected function resolveSalesTaxAdjustment(int $subtotal, int $profitTotal, int $taxTotal, int $invoiceTotal): ?Adjustment
    {
        if ($taxTotal <= 0) {
            return null;
        }

        $preTaxTotal = max($subtotal + $profitTotal, 0);

        if ($preTaxTotal <= 0) {
            return null;
        }

        $rate = (int) round(($taxTotal / $preTaxTotal) * 100 * 10000);
        $taxAccountId = $this->resolveSalesTaxAccountId($invoiceTotal);

        $adjustment = Adjustment::query()
            ->where('company_id', $this->companyId)
            ->where('category', AdjustmentCategory::Tax)
            ->where('type', AdjustmentType::Sales)
            ->where('status', AdjustmentStatus::Active)
            ->where('account_id', $taxAccountId)
            ->where('rate', $rate)
            ->first();

        if ($adjustment) {
            return $adjustment;
        }

        return Adjustment::query()->create([
            'company_id' => $this->companyId,
            'account_id' => $taxAccountId,
            'name' => sprintf('Legacy Sales Tax %.2f%%', $rate / 10000),
            'status' => AdjustmentStatus::Active,
            'category' => AdjustmentCategory::Tax,
            'type' => AdjustmentType::Sales,
            'recoverable' => false,
            'rate' => $rate,
            'computation' => AdjustmentComputation::Percentage,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
        ]);
    }

    protected function resolveSalesTaxAccountId(int $invoiceTotal): int
    {
        if ($invoiceTotal > 1_000_000_000 && Adjustment::companyHasPpnWapu($this->companyId)) {
            return Account::getAccountsWapu($this->companyId)->id;
        }

        return Account::getAccountsPPn($this->companyId)->id;
    }

    protected function warmProjectReferenceMap(): void
    {
        Project::query()
            ->where('company_id', $this->companyId)
            ->get()
            ->each(fn (Project $project) => $this->registerProjectReference($project));
    }

    protected function registerProjectReference(Project $project): void
    {
        if (! $project->number) {
            return;
        }

        foreach ($this->projectReferenceAliases($project->number) as $alias) {
            $this->projectReferenceMap[$alias] = $project;
        }

        if ($project->work_basis) {
            foreach ($this->workBasisAliases($project->work_basis) as $alias) {
                $this->workBasisMap[$alias] = $project;
            }
        }
    }

    protected function projectReferenceAliases(string $reference, ?string $invoiceDate = null): array
    {
        $reference = strtoupper(str_replace(["\n", "\r", ' '], '', $reference));

        if ($reference === '') {
            return [];
        }

        $aliases = [
            $reference,
            preg_replace('/[^A-Z0-9]/', '', $reference) ?? $reference,
        ];

        if (preg_match('/^([A-Z]+)(\d{2})-(\d{3})$/', $reference, $matches)) {
            $aliases[] = $matches[1] . $matches[3];
            $aliases[] = $matches[1] . $matches[2] . $matches[3];
        }

        if ($invoiceDate && preg_match('/^([A-Z]+)(\d{3})$/', $reference, $matches)) {
            $aliases[] = $matches[1] . Carbon::parse($invoiceDate)->format('y') . '-' . $matches[2];
        }

        return array_values(array_unique(array_filter($aliases)));
    }

    protected function buildProjectNumber(mixed $prefix, mixed $number): ?string
    {
        $prefix = $this->cleanText($prefix);
        $number = $this->cleanText($number);

        if (! $number && ! $prefix) {
            return null;
        }

        $projectNumber = $number;

        if ($prefix && $number && ! str_starts_with($number, $prefix)) {
            $projectNumber = $prefix . $number;
        }

        if ($projectNumber && preg_match('/^([A-Z]+\d{2})-?(\d+)$/i', $projectNumber, $matches)) {
            return strtoupper($matches[1]) . '-' . str_pad((string) ((int) $matches[2]), 3, '0', STR_PAD_LEFT);
        }

        return $projectNumber ? strtoupper($projectNumber) : null;
    }

    protected function buildLegacyProjectName(string $projectNumber): string
    {
        return "Data Lama - {$projectNumber}";
    }

    protected function normalizeDocumentNumber(mixed $value): ?string
    {
        $value = $this->cleanText($value);

        if (! $value) {
            return null;
        }

        if (preg_match('/^\d+(?:\.0+)?$/', $value)) {
            return null;
        }

        return strtoupper($value);
    }

    protected function parseDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $this->formatLegacyDate($value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $this->formatLegacyDate(Carbon::instance($value));
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            $serial = (float) $value;

            if ($serial >= 20_000 && $serial <= 70_000) {
                try {
                    return $this->formatLegacyDate(Carbon::instance(ExcelDate::excelToDateTimeObject($serial)));
                } catch (Throwable) {
                    // Fall through to string parsing.
                }
            }
        }

        $value = trim((string) $value);

        if ($value === '' || $value === '#N/A') {
            return null;
        }

        $value = $this->normalizeIndonesianDateString($value);

        // 2-digit year formats must be tried before 4-digit (Y) to avoid 06/01/25 → 0025-01-06.
        $formats = [
            'Y-m-d',
            'd/m/y',
            'd-m-y',
            'd-M-y',
            'd-M-Y',
            'j M Y',
            'j M y',
            'd/m/Y',
            'd-m-Y',
            'm/d/y',
            'm/d/Y',
        ];

        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);

                if ($parsed !== false) {
                    $formatted = $this->formatLegacyDate($parsed);

                    if ($formatted !== null) {
                        return $formatted;
                    }
                }
            } catch (Throwable) {
                // Try the next format.
            }
        }

        try {
            return $this->formatLegacyDate(Carbon::parse($value));
        } catch (Throwable) {
            return null;
        }
    }

    protected function normalizeIndonesianDateString(string $value): string
    {
        $replacements = [
            'januari' => 'January',
            'februari' => 'February',
            'maret' => 'March',
            'april' => 'April',
            'mei' => 'May',
            'juni' => 'June',
            'juli' => 'July',
            'agustus' => 'August',
            'september' => 'September',
            'oktober' => 'October',
            'november' => 'November',
            'desember' => 'December',
            'okt' => 'Oct',
            'nov' => 'Nov',
            'des' => 'Dec',
        ];

        $normalized = $value;

        foreach ($replacements as $from => $to) {
            $normalized = preg_replace('/\b' . preg_quote($from, '/') . '\b/iu', $to, $normalized) ?? $normalized;
        }

        // Common Excel typo: "1 Nov 0256" should be 2025.
        $normalized = preg_replace('/\b0256\b/', '2025', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b0255\b/', '2025', $normalized) ?? $normalized;

        return trim($normalized);
    }

    protected function formatLegacyDate(Carbon $date): ?string
    {
        $normalized = $this->normalizeLegacyDate($date->copy());

        if ($normalized->year < 1990 || $normalized->year > 2035) {
            return null;
        }

        return $normalized->toDateString();
    }

    protected function normalizeLegacyDate(Carbon $date): Carbon
    {
        if ($date->year < 1900) {
            $date->year = 2000 + ($date->year % 100);
        }

        return $date;
    }

    protected function parseMoneyToMinor(mixed $value): int
    {
        return $this->parseMoneyMajorUnits($value) * $this->moneySubunit();
    }

    protected function parseMoneyMajorUnits(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        $value = trim((string) $value);

        if ($value === '' || $value === '#N/A' || $value === '#REF!') {
            return 0;
        }

        $normalized = str_replace(["\u{00A0}", ' '], '', $value);
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');
        $numeric = preg_replace('/[^0-9.,]/', '', $normalized) ?? '';

        if ($numeric === '') {
            return 0;
        }

        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $numeric)) {
            $major = (int) preg_replace('/[.,]/', '', $numeric);

            return $negative ? -$major : $major;
        }

        if (preg_match('/^\d+[.,]\d{1,2}$/', $numeric)) {
            $decimal = (float) str_replace(',', '.', $numeric);
            $major = (int) round($decimal);

            return $negative ? -$major : $major;
        }

        $major = (int) preg_replace('/\D/', '', $numeric);

        return $negative ? -abs($major) : $major;
    }

    protected function moneySubunit(): int
    {
        return (int) (config('money.currencies.IDR.subunit') ?? 100);
    }

    protected function cleanText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';

        return $value !== '' ? $value : null;
    }

    protected function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->cleanText($value) !== null) {
                return false;
            }
        }

        return true;
    }

    protected function dateTimeOrNow(?string $date): Carbon
    {
        return $date ? Carbon::parse($date)->startOfDay() : now();
    }

    protected function syncInvoiceUpdatedAt(Invoice $invoice, Carbon $timestamp): void
    {
        $invoice->forceFill([
            'updated_at' => $timestamp,
        ])->saveQuietly();
    }

    protected function legacyCreatedAt(?string ...$dates): Carbon
    {
        foreach ($dates as $date) {
            if ($date) {
                return Carbon::parse($date)->startOfDay();
            }
        }

        return now();
    }

    protected function addWarning(string $message): void
    {
        $this->summary['warnings'][] = $message;
    }
}
