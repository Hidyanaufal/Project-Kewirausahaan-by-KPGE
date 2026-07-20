<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\OpeningBalanceNameImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class OpeningBalanceNameBatch extends Command
{
    protected $signature = 'accounting:opening-balance-name-batch
                            {--config=storage/app/ob-company-templates.json : Path config templates}
                            {--year= : Override year dari config}
                            {--only= : Filter key (comma), mis. kpg,jes}
                            {--mode=preview : preview|import}
                            {--execute : Write DB (hanya mode=import)}
                            {--company-id= : Override mapping key:id, mis. kpg:1,jes:2}';

    protected $description = 'Batch preview/import saldo awal by-name untuk beberapa company + file Excel COA_*';

    public function handle(OpeningBalanceNameImportService $service): int
    {
        $configPath = base_path((string) $this->option('config'));
        if (! is_file($configPath)) {
            // also allow absolute / storage path
            $alt = (string) $this->option('config');
            if (is_file($alt)) {
                $configPath = $alt;
            } elseif (is_file(storage_path('app/'.ltrim($alt, '/')))) {
                $configPath = storage_path('app/'.ltrim(str_replace('storage/app/', '', $alt), '/'));
            } else {
                $this->error("Config tidak ditemukan: {$this->option('config')}");

                return self::FAILURE;
            }
        }

        $config = json_decode((string) file_get_contents($configPath), true);
        if (! is_array($config) || empty($config['templates'])) {
            $this->error('Config tidak valid (butuh templates[]).');

            return self::FAILURE;
        }

        $year = (int) ($this->option('year') ?: ($config['year'] ?? 2026));
        $mode = strtolower((string) $this->option('mode') ?: 'preview');
        if (! in_array($mode, ['preview', 'import'], true)) {
            $this->error('--mode harus preview atau import.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        if ($mode === 'preview' && $execute) {
            $this->warn('--execute diabaikan pada mode=preview.');
            $execute = false;
        }

        $only = $this->parseOnly((string) ($this->option('only') ?: ''));
        $overrides = $this->parseCompanyIdOverrides((string) ($this->option('company-id') ?: ''));

        $templates = collect($config['templates'])
            ->filter(fn (array $t) => $only === [] || in_array($t['key'] ?? '', $only, true))
            ->values();

        if ($templates->isEmpty()) {
            $this->warn('Tidak ada template yang diproses.');

            return self::SUCCESS;
        }

        $this->info("Batch OB by-name — mode={$mode}".($mode === 'import' ? ($execute ? ' LIVE' : ' dry-run') : '').", FY {$year}");
        $this->newLine();

        $exit = self::SUCCESS;

        foreach ($templates as $template) {
            $key = (string) ($template['key'] ?? '');
            $excelRel = (string) ($template['excel'] ?? '');
            $excelPath = $this->resolveExcelPath($excelRel);

            $this->line("<comment>=== {$key} ===</comment>");

            if ($excelPath === null) {
                $this->error("  Excel tidak ditemukan: {$excelRel}");
                $exit = self::FAILURE;

                continue;
            }

            try {
                $companyId = $overrides[$key] ?? $this->resolveCompanyId($template);
            } catch (Throwable $e) {
                $this->error('  '.$e->getMessage());
                $exit = self::FAILURE;

                continue;
            }

            $company = Company::query()->find($companyId);
            $this->line("  company_id={$companyId} ".($company?->name ?? ''));
            $this->line("  excel={$excelPath}");

            try {
                $matches = $service->matchFromExcel($companyId, $excelPath);
                $preview = $service->buildPreviewTable($matches);
                $s = $preview['summary'];

                $this->table(
                    ['Exact', 'Map', 'Create', 'Skip'],
                    [[$s['exact'], $s['map'], $s['create'], $s['skip']]]
                );

                $draftPath = storage_path("app/ob-mapping-{$key}-fy{$year}.draft.json");
                $draft = $service->buildDraftMapping($companyId, $year, $excelPath, $matches);
                $service->writeMappingFile($draftPath, $draft);
                $this->line("  draft → {$draftPath}");

                if ($mode === 'import') {
                    $mapping = $service->buildAutoApprovedMappingFromMatches($companyId, $year, $excelPath, $matches);
                    $approvedPath = storage_path("app/ob-mapping-{$key}-fy{$year}.auto.json");
                    $service->writeMappingFile($approvedPath, $mapping);

                    $plan = $service->importApprovedMapping($mapping, $execute);
                    $imported = collect($plan)->where(fn ($r) => ! in_array($r[2] ?? '', ['skip', '?'], true))->count();
                    $skipped = collect($plan)->where(fn ($r) => ($r[2] ?? '') === 'skip')->count();
                    $this->info($execute
                        ? "  import LIVE: proses={$imported}, skip={$skipped} (mapping {$approvedPath})"
                        : "  import dry-run: proses={$imported}, skip={$skipped} — tambah --execute untuk write");
                }
            } catch (Throwable $e) {
                $this->error('  Gagal: '.$e->getMessage());
                $exit = self::FAILURE;
            }

            $this->newLine();
        }

        return $exit;
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function resolveCompanyId(array $template): int
    {
        if (! empty($template['company_id'])) {
            return (int) $template['company_id'];
        }

        $needles = $template['company_name_contains'] ?? [];
        if (! is_array($needles) || $needles === []) {
            throw new \InvalidArgumentException('Template tanpa company_id / company_name_contains.');
        }

        $companies = Company::query()->orderBy('id')->get(['id', 'name']);
        $matches = $companies->filter(function (Company $company) use ($needles) {
            $name = Str::lower((string) $company->name);
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($name, Str::lower((string) $needle))) {
                    return true;
                }
            }

            return false;
        })->values();

        if ($matches->count() === 1) {
            return (int) $matches->first()->id;
        }

        if ($matches->isEmpty()) {
            throw new \InvalidArgumentException(
                'Company tidak ketemu untuk needles: '.implode(', ', $needles)
                .' — pakai --company-id=key:id'
            );
        }

        $list = $matches->map(fn (Company $c) => "{$c->id}:{$c->name}")->implode(', ');
        throw new \InvalidArgumentException("Company ambiguous ({$list}) — pakai --company-id=key:id");
    }

    private function resolveExcelPath(string $excelRel): ?string
    {
        $candidates = [
            base_path($excelRel),
            storage_path('app/'.$excelRel),
            $excelRel,
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function parseOnly(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn (string $v) => Str::lower(trim($v)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function parseCompanyIdOverrides(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $map = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || ! str_contains($part, ':')) {
                continue;
            }
            [$key, $id] = array_map('trim', explode(':', $part, 2));
            if ($key !== '' && ctype_digit($id)) {
                $map[Str::lower($key)] = (int) $id;
            }
        }

        return $map;
    }
}
