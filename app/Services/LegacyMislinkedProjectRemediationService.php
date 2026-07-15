<?php

namespace App\Services;

use App\Enums\Addons\ProjectStatus;
use App\Models\Accounting\Invoice;
use App\Models\Addons\Project;
use App\Models\Addons\ProjectType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LegacyMislinkedProjectRemediationService
{
    /**
     * @param  list<string>  $invoiceNumbers
     * @return array{scanned: int, remediated: int, skipped: int, details: list<array<string, mixed>>}
     */
    public function remediate(
        int $companyId,
        int $actorId,
        array $invoiceNumbers = [],
        bool $dryRun = false,
    ): array {
        $query = Invoice::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_imported', true)
            ->with(['project', 'lineItems']);

        if ($invoiceNumbers !== []) {
            $query->whereIn('invoice_number', $invoiceNumbers);
        }

        $summary = [
            'scanned' => 0,
            'remediated' => 0,
            'skipped' => 0,
            'details' => [],
        ];

        foreach ($query->orderBy('id')->get() as $invoice) {
            $summary['scanned']++;

            $project = $invoice->project;
            $description = $this->primaryLineDescription($invoice);

            if (! $project || $description === '') {
                $summary['skipped']++;
                $summary['details'][] = [
                    'invoice_number' => $invoice->invoice_number,
                    'status' => 'skipped',
                    'reason' => 'missing_project_or_description',
                ];

                continue;
            }

            if ($this->descriptionMatchesProject($description, $project)) {
                $summary['skipped']++;
                $summary['details'][] = [
                    'invoice_number' => $invoice->invoice_number,
                    'status' => 'skipped',
                    'reason' => 'description_matches_project',
                    'project_number' => $project->number,
                ];

                continue;
            }

            $detail = [
                'invoice_number' => $invoice->invoice_number,
                'status' => $dryRun ? 'would_remediate' : 'remediated',
                'old_project_id' => $project->id,
                'old_project_number' => $project->number,
                'old_project_name' => $project->name,
                'line_description' => $description,
            ];

            if ($dryRun) {
                $summary['remediated']++;
                $summary['details'][] = $detail;

                continue;
            }

            $newProject = DB::transaction(function () use ($invoice, $project, $description, $companyId, $actorId) {
                $stub = $this->createStubProjectFromInvoice(
                    invoice: $invoice,
                    description: $description,
                    sourceProject: $project,
                    companyId: $companyId,
                    actorId: $actorId,
                );

                $invoice->forceFill([
                    'project_id' => $stub->id,
                    'updated_by' => $actorId,
                    'is_imported' => true,
                ])->saveQuietly();

                return $stub;
            });

            $detail['new_project_id'] = $newProject->id;
            $detail['new_project_number'] = $newProject->number;
            $detail['new_project_name'] = $newProject->name;

            $summary['remediated']++;
            $summary['details'][] = $detail;
        }

        return $summary;
    }

    protected function primaryLineDescription(Invoice $invoice): string
    {
        foreach ($invoice->lineItems as $lineItem) {
            $description = trim((string) ($lineItem->description ?? ''));

            if ($description !== '') {
                return $description;
            }
        }

        return '';
    }

    protected function descriptionMatchesProject(string $description, Project $project): bool
    {
        $haystack = mb_strtolower(trim(($project->name ?? '') . ' ' . ($project->description ?? '')));

        if ($haystack === '') {
            return false;
        }

        $tokens = $this->significantDescriptionTokens(mb_strtolower($description));

        if ($tokens === []) {
            return false;
        }

        $matched = 0;

        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) {
                $matched++;
            }
        }

        $ratio = $matched / count($tokens);

        return $matched >= 2 || $ratio >= 0.35;
    }

    /**
     * @return list<string>
     */
    protected function significantDescriptionTokens(string $text): array
    {
        $stopWords = [
            'yang', 'dari', 'untuk', 'dalam', 'pada', 'dengan', 'adalah', 'serta', 'atau', 'ini', 'itu',
            'tagihan', 'termin', 'pekerjaan', 'jasa', 'nilai', 'kontrak', 'rangka', 'dan', 'the', 'of',
            'ke', 'di', 'oleh', 'atas', 'bagi',
        ];

        $words = preg_split('/[^a-z0-9]+/u', $text) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $word): bool => mb_strlen($word) >= 4 && ! in_array($word, $stopWords, true)
        )));
    }

    protected function createStubProjectFromInvoice(
        Invoice $invoice,
        string $description,
        Project $sourceProject,
        int $companyId,
        int $actorId,
    ): Project {
        $projectTypeId = $sourceProject->project_type_id
            ?? ProjectType::query()->where('company_id', $companyId)->orderBy('id')->value('id');

        $stubNumber = $this->buildStubNumber($invoice->invoice_number);

        $existing = Project::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('number', $stubNumber)
            ->first();

        if ($existing) {
            $existing->forceFill([
                'name' => Str::limit($description, 250, ''),
                'description' => $description,
                'is_imported' => true,
            ])->saveQuietly();

            return $existing->refresh();
        }

        $project = new Project();
        $project->forceFill([
            'company_id' => $companyId,
            'project_type_id' => $projectTypeId,
            'client_id' => $invoice->client_id ?: $sourceProject->client_id,
            'pic_id' => $sourceProject->pic_id ?: $actorId,
            'number' => $stubNumber,
            'name' => Str::limit($description, 250, ''),
            'description' => $description,
            'status' => ProjectStatus::Approved,
            'is_imported' => true,
        ]);
        $project->saveQuietly();

        return $project;
    }

    protected function buildStubNumber(string $invoiceNumber): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $invoiceNumber) ?? $invoiceNumber);

        return 'LEG-' . $normalized;
    }
}
