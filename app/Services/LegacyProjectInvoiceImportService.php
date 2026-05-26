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
use Throwable;

class LegacyProjectInvoiceImportService
{
    protected array $summary = [
        'projects' => [
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ],
        'invoices' => [
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ],
        'warnings' => [],
    ];

    protected array $invoiceSupplementRows = [];

    protected array $projectReferenceMap = [];

    protected array $clientCache = [];

    protected array $picCache = [];

    protected ?Offering $defaultOffering = null;

    protected ?BankAccount $activeBankAccount = null;

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
        $projectType = ProjectType::query()
            ->where('company_id', $this->companyId)
            ->whereRaw('LOWER(name) = ?', [strtolower($projectTypeName)])
            ->first();

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

                $attributes = [
                    'company_id' => $this->companyId,
                    'project_type_id' => $projectType->id,
                    'client_id' => $clientId,
                    'pic_id' => $this->resolvePicId($mapped['pic_internal']),
                    'number' => $mapped['number'],
                    'name' => $mapped['name'],
                    'description' => $mapped['description'],
                    'request_number_date' => $mapped['request_number_date'],
                    'project_value' => $mapped['project_value'],
                    'start_date' => $mapped['start_date'],
                    'end_date' => $mapped['end_date'],
                    'work_basis' => $mapped['work_basis'],
                    'status' => ProjectStatus::Approved,
                    'pic_client_name' => $mapped['pic_client_name'],
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
                    $project->forceFill($attributes);
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

    public function importInvoiceSheet(array $rows): void
    {
        foreach ($rows as $row) {
            $lineNumber = $row['row_number'] ?? null;
            $mapped = $this->mapInvoiceRow($row['values'] ?? []);

            if (! $mapped || empty($mapped['invoice_number'])) {
                continue;
            }

            try {
                $project = $this->resolveProjectForInvoice($mapped['project_reference'], $mapped['date']);

                if (! $project) {
                    $this->summary['invoices']['failed']++;
                    $this->addWarning("Invoice '{$mapped['invoice_number']}' dilewati karena project '{$mapped['project_reference']}' tidak ditemukan.");

                    continue;
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

                if ($existing && $existing->payments()->exists()) {
                    $this->summary['invoices']['skipped']++;
                    $this->addWarning("Invoice '{$mapped['invoice_number']}' sudah punya payment transaction, sehingga import update dilewati.");

                    continue;
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

        $invoice->forceFill([
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
            'is_imported' => true,
        ]);
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
        ]);

        $taxAdjustment = $this->resolveSalesTaxAdjustment($data['subtotal'], $data['profit_total'], $data['tax_total'], $data['total']);

        if ($taxAdjustment) {
            $lineItem->refresh();
            $lineItem->salesTaxes()->sync([$taxAdjustment->id]);
            $lineItem->updateQuietly([
                'tax_total' => $lineItem->calculateTaxTotalAmount(),
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
            'is_imported' => true,
        ])->save();

        $invoice->refresh();
        $invoice->approveDraft($this->dateTimeOrNow($data['date']));

        $finalStatus = $data['status'];
        if (in_array($finalStatus, [InvoiceStatus::Sent, InvoiceStatus::Overdue, InvoiceStatus::Viewed, InvoiceStatus::Paid, InvoiceStatus::Partial, InvoiceStatus::Cancelled], true)) {
            $invoice->markAsSent($this->dateTimeOrNow($data['date']));
            $invoice->refresh();
        }

        if ($finalStatus === InvoiceStatus::Cancelled) {
            $invoice->cancelWithAccountingVoid();
            $invoice->forceFill([
                'is_imported' => true,
                'updated_by' => $this->actorId,
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

            return;
        }

        if ($finalStatus === InvoiceStatus::Partial) {
            $amountPaid = min($data['paid_value'], (int) $invoice->actual_total);
            $invoice->forceFill([
                'status' => InvoiceStatus::Partial,
                'amount_paid' => $amountPaid,
                'updated_by' => $this->actorId,
                'is_imported' => true,
            ])->saveQuietly();

            return;
        }

        if ($finalStatus === InvoiceStatus::Viewed) {
            $invoice->markAsViewed($this->dateTimeOrNow($data['date']));

            return;
        }

        if ($finalStatus === InvoiceStatus::Sent) {
            $invoice->forceFill([
                'updated_by' => $this->actorId,
                'is_imported' => true,
            ])->saveQuietly();
        }
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

    protected function mapInvoiceRow(array $values): ?array
    {
        if ($this->isEmptyRow($values)) {
            return null;
        }

        $invoiceNumber = $this->normalizeDocumentNumber($values[2] ?? null);

        if (! $invoiceNumber) {
            return null;
        }

        $supplement = $this->invoiceSupplementRows[$invoiceNumber] ?? [];
        $mainProjectReference = $this->cleanText($values[3] ?? null);
        $mainDescription = $this->cleanText($values[4] ?? null);
        $preTaxTotal = $supplement['pre_tax_total'] ?? $this->deriveInvoicePretaxTotal($values);
        $subtotal = $supplement['subtotal'] ?? $preTaxTotal;
        $profitTotal = $supplement['profit_total'] ?? max($preTaxTotal - $subtotal, 0);
        $taxTotal = $supplement['tax_total'] ?? $this->parseMoneyToMinor($values[7] ?? null);
        $total = $supplement['total'] ?? max($preTaxTotal + $taxTotal, 0);
        $paidValue = $supplement['paid_value'] ?? $this->parseMoneyToMinor($values[10] ?? null);
        $status = $this->normalizeInvoiceStatus($supplement['status'] ?? ($values[9] ?? null), $paidValue);
        $profitRate = $subtotal > 0 && $profitTotal > 0
            ? (int) round(($profitTotal / $subtotal) * 100 * 10000)
            : 0;

        return [
            'invoice_number' => $invoiceNumber,
            'date' => $supplement['date'] ?? $this->parseDate($values[1] ?? null),
            'due_date' => $supplement['due_date'] ?? ($supplement['date'] ?? $this->parseDate($values[1] ?? null)),
            'project_reference' => $mainProjectReference ?: $this->cleanText($supplement['project_reference'] ?? null),
            'item_description' => $mainDescription ?: $this->cleanText($supplement['item_description'] ?? null),
            'subtotal' => $subtotal,
            'profit_total' => $profitTotal,
            'profit_rate' => $profitRate,
            'tax_total' => $taxTotal,
            'total' => $total,
            'status' => $status,
            'paid_value' => $paidValue,
            'tax_number' => $this->cleanText($supplement['tax_number'] ?? null),
            'pph23' => $status === InvoiceStatus::Paid && $preTaxTotal > $paidValue && $paidValue > 0
                ? $preTaxTotal - $paidValue
                : null,
        ];
    }

    protected function recordImportedPaidPayment(Invoice $invoice, int $amount, ?string $postedAt, ?int $pph23): void
    {
        if ($amount <= 0) {
            return;
        }

        $invoice->forceFill([
            'actual_total' => $amount,
            'updated_by' => $this->actorId,
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
            'is_imported' => true,
        ])->saveQuietly();
    }

    protected function mapSupplementInvoiceRow(array $values): ?array
    {
        if ($this->isEmptyRow($values)) {
            return null;
        }

        $invoiceNumber = $this->normalizeDocumentNumber(($values[4] ?? null) ?: ($values[20] ?? null));

        if (! $invoiceNumber) {
            return null;
        }

        $nominalInvoice = $this->parseMoneyToMinor($values[10] ?? null);
        $baseOrProfit = $this->parseMoneyToMinor($values[11] ?? null);
        $preTaxTotal = $this->parseMoneyToMinor(($values[12] ?? null) ?: ($values[10] ?? null));

        [$subtotal, $profitTotal] = $this->deriveSubtotalAndProfitFromSupplement(
            nominalInvoice: $nominalInvoice,
            baseOrProfit: $baseOrProfit,
            preTaxTotal: $preTaxTotal,
        );

        return [
            'invoice_number' => $invoiceNumber,
            'date' => $this->parseDate($values[5] ?? null),
            'due_date' => $this->parseDate($values[15] ?? null),
            'project_reference' => $this->cleanText($values[8] ?? null),
            'item_description' => $this->cleanText($values[9] ?? null),
            'pre_tax_total' => $preTaxTotal,
            'subtotal' => $subtotal,
            'profit_total' => $profitTotal,
            'tax_total' => $this->parseMoneyToMinor($values[13] ?? null),
            'total' => $this->parseMoneyToMinor($values[14] ?? null),
            'status' => $this->cleanText($values[19] ?? null),
            'paid_value' => $this->parseMoneyToMinor($values[21] ?? null),
            'tax_number' => $this->cleanText($values[18] ?? null),
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
        $total = $this->parseMoneyToMinor($values[8] ?? null);
        $tax = $this->parseMoneyToMinor($values[7] ?? null);

        if ($total > 0 && $tax >= 0) {
            return max($total - $tax, 0);
        }

        return $this->parseMoneyToMinor(($values[6] ?? null) ?: ($values[5] ?? null));
    }

    protected function normalizeInvoiceStatus(mixed $rawStatus, int $paidValue): InvoiceStatus
    {
        $normalized = strtoupper(trim((string) $rawStatus));

        return match (true) {
            $normalized === 'BATAL' => InvoiceStatus::Cancelled,
            $normalized === 'PAID' => InvoiceStatus::Paid,
            $paidValue > 0 => InvoiceStatus::Partial,
            default => InvoiceStatus::Sent,
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
        } else {
            $query->where('number', $reference);
        }

        $project = $query->orderByDesc('id')->first();

        if ($project) {
            $this->registerProjectReference($project);
        }

        return $project;
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
            return $value->toDateString();
        }

        $value = trim((string) $value);

        if ($value === '' || $value === '#N/A') {
            return null;
        }

        $formats = ['Y-m-d', 'd/m/Y', 'd/m/y', 'd-m-Y', 'm/d/Y'];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->toDateString();
            } catch (Throwable) {
                // Try the next format.
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    protected function parseMoneyToMinor(mixed $value): int
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
            $minor = (int) preg_replace('/[.,]/', '', $numeric);

            return $negative ? -$minor : $minor;
        }

        if (preg_match('/^\d+[.,]\d{1,2}$/', $numeric)) {
            $decimal = (float) str_replace(',', '.', $numeric);
            $minor = (int) round($decimal);

            return $negative ? -$minor : $minor;
        }

        $minor = (int) preg_replace('/\D/', '', $numeric);

        return $negative ? -abs($minor) : $minor;
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

    protected function addWarning(string $message): void
    {
        $this->summary['warnings'][] = $message;
    }
}
