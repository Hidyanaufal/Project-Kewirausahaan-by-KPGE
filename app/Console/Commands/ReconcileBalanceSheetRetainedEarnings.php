<?php

namespace App\Console\Commands;

use App\Models\Accounting\Account;
use App\Models\Company;
use App\Services\ReportService;
use App\Utilities\Currency\CurrencyConverter;
use Illuminate\Console\Command;

class ReconcileBalanceSheetRetainedEarnings extends Command
{
    protected $signature = 'accounting:reconcile-retained-earnings
                            {--company-id= : Batasi ke satu company ID}
                            {--as-of= : Tanggal neraca (Y-m-d), default hari ini}
                            {--dry-run : Audit saja tanpa mengubah data (default)}
                            {--apply : Izinkan posting penutupan (belum diimplementasikan — audit only)}';

    protected $description = 'Audit selisih neraca terkait retained earnings GL vs terhitung (Revenue−Expense)';

    public function handle(ReportService $reportService): int
    {
        if ($this->option('apply')) {
            $this->error('Mode --apply belum diimplementasikan. Gunakan audit untuk rekomendasi jurnal penutupan manual.');

            return self::FAILURE;
        }

        $asOf = $this->option('as-of') ?? now()->toDateString();
        $companyIds = $this->resolveCompanyIds();

        if ($companyIds === []) {
            $this->warn('Tidak ada company yang diproses.');

            return self::SUCCESS;
        }

        $this->info("Audit balance sheet retained earnings — as of {$asOf}");
        $this->newLine();

        foreach ($companyIds as $companyId) {
            $this->auditCompany($reportService, (int) $companyId, $asOf);
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function resolveCompanyIds(): array
    {
        if ($companyId = $this->option('company-id')) {
            return [(int) $companyId];
        }

        return Company::query()->orderBy('id')->pluck('id')->all();
    }

    private function auditCompany(ReportService $reportService, int $companyId, string $asOf): void
    {
        $company = Company::query()->find($companyId);
        $label = $company?->name ?? "Company #{$companyId}";

        $this->line("<comment>{$label}</comment> (ID {$companyId})");

        $report = $reportService->buildBalanceSheetReport($asOf, [], null, $companyId);

        $assets = CurrencyConverter::convertToFloat($report->categories['Assets']->summary->endingBalance ?? '0');
        $liabilities = CurrencyConverter::convertToFloat($report->categories['Liabilities']->summary->endingBalance ?? '0');
        $equity = CurrencyConverter::convertToFloat($report->categories['Equity']->summary->endingBalance ?? '0');
        $diff = $assets - $liabilities - $equity;

        $startDate = $reportService->getEarliestTransactionDate();
        $computedRe = $reportService->calculateRetainedEarnings($startDate, $asOf, null, $companyId)->getAmount() / 100;
        $addComputed = Account::shouldAddComputedRetainedEarningsToBalanceSheet($companyId);

        $retainedGlAccounts = Account::query()
            ->where('company_id', $companyId)
            ->where('category', \App\Enums\Accounting\AccountCategory::Equity)
            ->where(function ($query) {
                $query->where('code', 'like', '3200%')
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%retained earn%']);
            })
            ->get(['id', 'code', 'name']);

        $this->table(
            ['Metrik', 'Nilai (IDR)'],
            [
                ['Total Assets', number_format($assets, 2, ',', '.')],
                ['Total Liabilities', number_format($liabilities, 2, ',', '.')],
                ['Total Equity (laporan)', number_format($equity, 2, ',', '.')],
                ['Assets − (Liabilities + Equity)', number_format($diff, 2, ',', '.')],
                ['Computed RE (Rev−Exp)', number_format($computedRe, 2, ',', '.')],
                ['Tambah computed RE ke total?', $addComputed ? 'Ya' : 'Tidak (ada akun GL RE)'],
            ]
        );

        if ($retainedGlAccounts->isNotEmpty()) {
            $this->line('Akun retained earnings GL:');
            foreach ($retainedGlAccounts as $account) {
                $this->line("  - {$account->code} {$account->name}");
            }
        }

        if (abs($diff) > 500_000_000) {
            $this->warn('Selisih > Rp 500 juta — periksa penutupan Revenue/Expense ke akun retained earnings.');
        } elseif (abs($diff) > 1) {
            $this->comment('Selisih residual — pertimbangkan penutupan buku atau rekonsiliasi akun nominal.');
        } else {
            $this->info('Neraca seimbang (selisih < Rp 1).');
        }
    }
}
