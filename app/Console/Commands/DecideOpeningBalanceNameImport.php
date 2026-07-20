<?php

namespace App\Console\Commands;

use App\Models\Accounting\Account;
use App\Models\Company;
use App\Services\OpeningBalanceNameImportService;
use App\Utilities\Currency\CurrencyConverter;
use Illuminate\Console\Command;

class DecideOpeningBalanceNameImport extends Command
{
    protected $signature = 'accounting:decide-opening-balance-name-import
                            {--company-id= : Company ID (opsional, validasi terhadap mapping)}
                            {--year= : Tahun (opsional, validasi terhadap mapping)}
                            {--mapping= : Path draft mapping JSON dari preview}
                            {--output= : Path output mapping approved}
                            {--auto-accept-exact : Terima semua baris exact tanpa tanya (rename=n)}';

    protected $description = 'Putuskan mapping per baris (pilih akun / create / skip + rename Excel y/n). Tidak menulis ke DB.';

    public function handle(OpeningBalanceNameImportService $service): int
    {
        $mappingPath = (string) ($this->option('mapping') ?: '');
        if ($mappingPath === '' || ! is_file($mappingPath)) {
            $this->error('--mapping wajib dan harus ada.');

            return self::FAILURE;
        }

        $mapping = $service->loadMappingFile($mappingPath);
        $companyId = (int) ($this->option('company-id') ?: ($mapping['company_id'] ?? 0));
        $year = (int) ($this->option('year') ?: ($mapping['year'] ?? 0));
        $output = (string) ($this->option('output') ?: preg_replace('/\.draft\.json$/', '.approved.json', $mappingPath));

        if ($output === $mappingPath) {
            $output = preg_replace('/\.json$/', '.approved.json', $mappingPath) ?: ($mappingPath . '.approved.json');
        }

        if ($companyId <= 0 || ! Company::query()->whereKey($companyId)->exists()) {
            $this->error('company-id tidak valid.');

            return self::FAILURE;
        }

        $autoAcceptExact = (bool) $this->option('auto-accept-exact');
        $rows = $mapping['rows'] ?? [];
        $decidedRows = [];

        $this->info('Decide mapping — tidak ada perubahan DB. Hasil ditulis ke file approved.');
        $this->newLine();

        foreach ($rows as $row) {
            $decidedRows[] = $this->decideRow($row, $autoAcceptExact, $companyId, $service);
        }

        $decidedRows = $this->injectCreateParentRows($decidedRows);

        $approved = [
            'version' => $mapping['version'] ?? 1,
            'approved' => true,
            'approved_at' => now()->toIso8601String(),
            'company_id' => $companyId,
            'year' => $year ?: ($mapping['year'] ?? 2026),
            'excel_path' => $mapping['excel_path'] ?? null,
            'rows' => $decidedRows,
        ];

        $pending = collect($decidedRows)->where('decision_status', 'pending');
        if ($pending->isNotEmpty()) {
            $this->error('Masih ada baris pending — mapping tidak di-approve.');

            return self::FAILURE;
        }

        $service->writeMappingFile($output, $approved);
        $this->newLine();
        $this->info("Mapping approved ditulis ke: {$output}");
        $this->comment('Lanjut dry-run: php artisan accounting:import-opening-balance-by-name --mapping=' . $output);
        $this->comment('Live: tambah --execute');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function decideRow(array $row, bool $autoAcceptExact, int $companyId, OpeningBalanceNameImportService $service): array
    {
        $status = $row['status'] ?? '';
        $name = $row['excel_name'] ?? '(tanpa nama)';
        $balance = $this->formatMoney((int) ($row['balance_minor'] ?? 0));

        if (($row['decision_status'] ?? '') === 'skipped' || ($row['action'] ?? '') === 'skip') {
            $row['decision_status'] = 'skipped';
            $row['action'] = 'skip';
            $reason = $service->formatSkipReason($row['skip_reason'] ?? null);
            $this->line("<comment>⊘ Skip</comment> {$name} — {$reason}");

            return $row;
        }

        if ($status === 'exact' && $autoAcceptExact) {
            $row['decision_status'] = 'decided';
            $row['action'] = 'import';
            $row['rename_to_excel'] = false;
            $this->line("<info>✓</info> Exact auto-accept: {$name}");

            return $row;
        }

        if ($status === 'exact' && ! empty($row['account_id'])) {
            $this->newLine();
            $this->line("<comment>Exact</comment> {$name} | {$balance} → account_id={$row['account_id']}");
            if (! $this->confirm('Terima mapping exact ini?', true)) {
                return $this->askOverride($row, $companyId);
            }
            $row['decision_status'] = 'decided';
            $row['action'] = 'import';
            $row['rename_to_excel'] = $this->askRename($name, null, false);

            return $row;
        }

        $this->newLine();
        $this->line("<comment>[{$status}]</comment> {$name} | {$balance}");

        if ($status === 'new') {
            return $this->decideNew($row, $companyId);
        }

        return $this->decideWithCandidates($row, $companyId);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function decideNew(array $row, int $companyId): array
    {
        $suggested = $row['suggested_code'] ?? $row['code'] ?? $row['excel_code'] ?? null;
        $excelCode = $row['excel_code'] ?? null;
        $type = $row['account_type'] ?? $row['excel_account_type'] ?? null;
        $parentStatus = $row['parent_status'] ?? 'missing';
        $parentAction = $row['parent_action'] ?? 'none';

        $this->line('Parent Excel: ' . ($row['excel_parent_account_no'] ?? '-') . ' ' . ($row['excel_parent_name'] ?? '-'));
        if ($parentStatus === 'found_existing') {
            $this->line('Parent sistem: ' . ($row['parent_system_code'] ?? '-') . ' ' . ($row['parent_system_name'] ?? '-') . " (id={$row['parent_account_id']})");
        } else {
            $this->line("Parent sistem: [{$parentStatus}]");
        }
        $this->line("Usulan code: {$suggested} (excel_code={$excelCode}, source=" . ($row['code_source'] ?? '-') . ')');
        $this->line("Type: {$type}");

        $choice = $this->choice(
            'Pilih tindakan',
            ['create' => 'Create akun baru', 'skip' => 'Skip baris ini'],
            'create'
        );

        if ($choice === 'skip') {
            $row['decision_status'] = 'skipped';
            $row['action'] = 'skip';
            $row['account_id'] = null;
            $row['rename_to_excel'] = null;

            return $row;
        }

        if ($parentStatus === 'needs_create' && ! empty($row['excel_parent_account_no'])) {
            $createParent = $this->confirm(
                'Parent belum ada di sistem. Buat parent dari Excel (' . $row['excel_parent_account_no'] . ' ' . $row['excel_parent_name'] . ')?',
                true
            );
            $parentAction = $createParent ? 'create_parent' : 'none';
        } elseif ($parentStatus === 'found_existing') {
            $parentAction = 'use_existing';
        } else {
            $parentAction = 'none';
        }

        $code = $this->ask('Kode akun child', $suggested);
        $type = $this->ask('Account type (label Excel)', $type);

        if (Account::query()->where('company_id', $companyId)->where('code', $code)->exists()) {
            $this->error("Kode {$code} sudah dipakai. Ulangi.");

            return $this->decideNew($row, $companyId);
        }

        $row['decision_status'] = 'decided';
        $row['action'] = 'create';
        $row['code'] = $code;
        $row['suggested_code'] = $suggested;
        $row['account_type'] = $type;
        $row['account_id'] = null;
        $row['rename_to_excel'] = false;
        $row['parent_action'] = $parentAction;

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function injectCreateParentRows(array $rows): array
    {
        $parents = [];
        foreach ($rows as $row) {
            if (($row['action'] ?? null) !== 'create') {
                continue;
            }
            if (($row['parent_action'] ?? null) !== 'create_parent') {
                continue;
            }

            $key = ($row['excel_parent_account_no'] ?? '') . '|' . ($row['excel_parent_name'] ?? '');
            if ($key === '|' || isset($parents[$key])) {
                continue;
            }

            $parents[$key] = [
                'row_number' => $row['excel_parent_row_number'] ?? 0,
                'excel_name' => $row['excel_parent_name'],
                'excel_code' => $row['excel_parent_account_no'],
                'excel_account_type' => $row['excel_parent_account_type'] ?? $row['account_type'] ?? null,
                'balance_minor' => 0,
                'status' => 'new_parent',
                'decision_status' => 'decided',
                'action' => 'create_parent',
                'code' => $row['excel_parent_account_no'],
                'account_type' => $row['excel_parent_account_type'] ?? $row['account_type'] ?? null,
                'account_id' => null,
                'rename_to_excel' => false,
                'parent_action' => 'create_parent',
            ];
        }

        return array_values(array_merge(array_values($parents), $rows));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function decideWithCandidates(array $row, int $companyId): array
    {
        $candidates = $row['candidates'] ?? [];
        $options = [];
        foreach ($candidates as $i => $candidate) {
            $n = (string) ($i + 1);
            $options[$n] = "[{$n}] {$candidate['code']} — {$candidate['name']} ({$candidate['similarity']}%)";
        }
        $options['c'] = 'Create akun baru';
        $options['s'] = 'Skip baris ini';

        $choice = $this->choice('Pilih tujuan', $options, array_key_first($options));

        if ($choice === 's') {
            $row['decision_status'] = 'skipped';
            $row['action'] = 'skip';
            $row['account_id'] = null;
            $row['rename_to_excel'] = null;

            return $row;
        }

        if ($choice === 'c') {
            $row['status'] = 'new';

            return $this->decideNew($row, $companyId);
        }

        $index = ((int) $choice) - 1;
        $candidate = $candidates[$index] ?? null;
        if ($candidate === null) {
            $this->error('Pilihan tidak valid.');

            return $this->decideWithCandidates($row, $companyId);
        }

        $account = Account::query()->where('company_id', $companyId)->find($candidate['id']);
        $row['decision_status'] = 'decided';
        $row['action'] = 'map';
        $row['account_id'] = (int) $candidate['id'];
        $row['chosen_from_candidate'] = $index;
        $row['rename_to_excel'] = $this->askRename(
            $row['excel_name'] ?? '',
            $account?->name,
            $account !== null && $this->namesDiffer($row['excel_name'] ?? '', $account->name)
        );

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function askOverride(array $row, int $companyId): array
    {
        $this->warn('Override exact — pilih ulang.');
        $accountId = (int) ($row['account_id'] ?? 0);
        $account = Account::query()->where('company_id', $companyId)->find($accountId);
        $row['candidates'] = $row['candidates'] ?? ($account ? [[
            'id' => $account->id,
            'code' => (string) $account->code,
            'name' => (string) $account->name,
            'similarity' => 100.0,
        ]] : []);
        $row['status'] = 'ambiguous';

        return $this->decideWithCandidates($row, $companyId);
    }

    private function askRename(string $excelName, ?string $systemName, bool $defaultYes): bool
    {
        if ($systemName !== null) {
            $this->line("Nama Excel: {$excelName}");
            $this->line("Nama sistem: {$systemName}");
        }

        return $this->confirm('Rename akun sistem sesuai nama Excel?', $defaultYes);
    }

    private function namesDiffer(string $excel, string $system): bool
    {
        return mb_strtolower(trim($excel)) !== mb_strtolower(trim($system));
    }

    private function formatMoney(int $minor): string
    {
        return CurrencyConverter::formatCentsToMoney($minor);
    }
}
