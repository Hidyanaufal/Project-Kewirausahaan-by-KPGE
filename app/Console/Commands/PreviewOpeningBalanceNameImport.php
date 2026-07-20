<?php

namespace App\Console\Commands;

use App\DTO\OpeningBalanceNameMatchDTO;
use App\Models\Company;
use App\Services\OpeningBalanceNameImportService;
use App\Utilities\Currency\CurrencyConverter;
use Illuminate\Console\Command;

class PreviewOpeningBalanceNameImport extends Command
{
    protected $signature = 'accounting:preview-opening-balance-name-import
                            {--company-id= : Company ID}
                            {--year=2026 : Tahun fiscal year saldo awal}
                            {--excel= : Path file Excel COA (Account Name + Balance)}
                            {--export-mapping= : Path output JSON draft mapping (belum approved)}';

    protected $description = 'Preview perbedaan nama Excel vs sistem untuk saldo awal (read-only, tanpa rename/create/post)';

    public function handle(OpeningBalanceNameImportService $service): int
    {
        $companyId = (int) ($this->option('company-id') ?: 0);
        $year = (int) $this->option('year');
        $excel = (string) ($this->option('excel') ?: '');

        if ($companyId <= 0 || ! Company::query()->whereKey($companyId)->exists()) {
            $this->error('company-id wajib dan harus valid.');

            return self::FAILURE;
        }

        if ($excel === '' || ! is_file($excel)) {
            $this->error('File --excel tidak ditemukan.');

            return self::FAILURE;
        }

        $matches = $service->matchFromExcel($companyId, $excel);

        $exact = $matches->where('status', 'exact');
        $similar = $matches->whereIn('status', ['fuzzy', 'ambiguous']);
        $new = $matches->where('status', 'new');
        $skipped = $matches->filter(fn (OpeningBalanceNameMatchDTO $dto) => str_starts_with($dto->status, 'skipped'));

        $this->info("Preview saldo awal by name — company {$companyId}, FY {$year}");
        $this->line('READ-ONLY: tidak ada rename, create akun, atau post jurnal.');
        $this->newLine();

        $this->renderExact($exact);
        $this->renderSimilar($similar);
        $this->renderNew($new);
        $this->renderSkipped($skipped, $service);

        $this->newLine();
        $this->table(
            ['Kelompok', 'Jumlah'],
            [
                ['Exact (suggested)', $exact->count()],
                ['Berbeda / mirip (pending)', $similar->count()],
                ['Baru (pending)', $new->count()],
                ['Skipped', $skipped->count()],
            ]
        );

        if ($export = $this->option('export-mapping')) {
            $draft = $service->buildDraftMapping($companyId, $year, $excel, $matches);
            $service->writeMappingFile($export, $draft);
            $this->info("Draft mapping ditulis ke: {$export}");
            $this->comment('Lanjut: php artisan accounting:decide-opening-balance-name-import --mapping=...');
        } else {
            $this->comment('Tip: tambah --export-mapping=storage/app/ob-mapping-fy2026.draft.json untuk tahap decide.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, OpeningBalanceNameMatchDTO>  $rows
     */
    private function renderExact($rows): void
    {
        $this->info('=== Exact (nama sama) — usulan import, rename belum diputuskan ===');
        if ($rows->isEmpty()) {
            $this->line('(kosong)');
            $this->newLine();

            return;
        }

        $this->table(
            ['Row', 'Excel Name', 'Account ID', 'Balance', 'Decision'],
            $rows->map(fn (OpeningBalanceNameMatchDTO $dto) => [
                $dto->rowNumber,
                $dto->excelName,
                $dto->accountId,
                $this->formatMoney($dto->balanceMinor),
                $dto->decisionStatus,
            ])->all()
        );
        $this->newLine();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, OpeningBalanceNameMatchDTO>  $rows
     */
    private function renderSimilar($rows): void
    {
        $this->info('=== Berbeda / mirip — pilih kandidat di tahap decide ===');
        if ($rows->isEmpty()) {
            $this->line('(kosong)');
            $this->newLine();

            return;
        }

        foreach ($rows as $dto) {
            $this->line("<comment>Row {$dto->rowNumber}</comment> [{$dto->status}] {$dto->excelName} | {$this->formatMoney($dto->balanceMinor)}");
            foreach ($dto->candidates as $i => $candidate) {
                $n = $i + 1;
                $this->line("  [{$n}] id={$candidate['id']} {$candidate['code']} — {$candidate['name']} ({$candidate['similarity']}%)");
            }
            $this->line('  account_id: (belum dipilih) | rename_to_excel: (belum diputuskan)');
            $this->newLine();
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, OpeningBalanceNameMatchDTO>  $rows
     */
    private function renderNew($rows): void
    {
        $this->info('=== Baru — belum dibuat sampai import --execute ===');
        if ($rows->isEmpty()) {
            $this->line('(kosong)');
            $this->newLine();

            return;
        }

        $this->table(
            ['Row', 'Excel Name', 'Parent Excel', 'Parent Sistem', 'Suggested', 'Excel Code', 'Balance'],
            $rows->map(fn (OpeningBalanceNameMatchDTO $dto) => [
                $dto->rowNumber,
                $dto->excelName,
                $dto->excelParentName
                    ? (($dto->excelParentAccountNo ?? '-') . ' ' . $dto->excelParentName)
                    : '-',
                $dto->parentSystemName
                    ? (($dto->parentSystemCode ?? '-') . ' ' . $dto->parentSystemName . ' [' . ($dto->parentStatus ?? '-') . ']')
                    : ('[' . ($dto->parentStatus ?? 'missing') . ']'),
                $dto->suggestedCode,
                $dto->excelCode,
                $this->formatMoney($dto->balanceMinor),
            ])->all()
        );
        $this->newLine();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, OpeningBalanceNameMatchDTO>  $rows
     */
    private function renderSkipped($rows, OpeningBalanceNameImportService $service): void
    {
        $this->info('=== Skipped (parent / saldo 0 / LTJ computed) ===');
        if ($rows->isEmpty()) {
            $this->line('(kosong)');
            $this->newLine();

            return;
        }

        $this->table(
            ['Row', 'Excel Name', 'Reason'],
            $rows->map(fn (OpeningBalanceNameMatchDTO $dto) => [
                $dto->rowNumber,
                $dto->excelName,
                $service->formatSkipReason($dto->skipReason ?? $dto->status),
            ])->all()
        );
        $this->newLine();
    }

    private function formatMoney(int $minor): string
    {
        return CurrencyConverter::formatCentsToMoney($minor);
    }
}
