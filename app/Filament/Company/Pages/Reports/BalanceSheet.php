<?php

namespace App\Filament\Company\Pages\Reports;

use App\Contracts\EnhancedReport;
use App\Contracts\ExportableReport;
use App\DTO\ReportDTO;
use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountType;
use App\Filament\Company\Pages\Concerns\HasReportTabs;
use App\Filament\Forms\Components\DateRangeSelect;
use App\Helpers\AuthHelper;
use App\Models\Company;
use App\Services\AccountService;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\Column;
use App\Transformers\BalanceSheetReportTransformer;
use Carbon\Carbon;
use Filament\Forms\Form;
use Filament\Support\Enums\Alignment;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BalanceSheet extends BaseReportPage implements EnhancedReport
{
    use HasReportTabs;

    protected static string $view = 'filament.company.pages.reports.balance-sheet';

    protected ReportService $reportService;

    protected ExportService $exportService;

    protected AccountService $accountService;

    public function boot(ReportService $reportService, ExportService $exportService, AccountService $accountService): void
    {
        $this->reportService = $reportService;
        $this->exportService = $exportService;
        $this->accountService = $accountService;
    }

    public function getTable(): array
    {
        return [
            Column::make('account_code')
                ->label('ACCOUNT CODE')
                ->toggleable(isToggledHiddenByDefault: true)
                ->alignment(Alignment::Left),
            Column::make('account_name')
                ->label('ACCOUNTS')
                ->alignment(Alignment::Left),
            Column::make('ending_balance')
                ->label($this->getDisplayAsOfDate())
                ->alignment(Alignment::Right),
        ];
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->inlineLabel()
            ->columns(3)
            ->schema([
                DateRangeSelect::make('dateRange')
                    ->label('As of')
                    ->selectablePlaceholder(false)
                    ->endDateField('asOfDate'),
                $this->getAsOfDateFormComponent()
                    ->hiddenLabel()
                    ->extraFieldWrapperAttributes([]),
            ]);
    }

    /**
     * Build report limited to the current company.
     */
    protected function buildReport(array $columns): ReportDTO
    {
        return $this->reportService->buildBalanceSheetReport(
            $this->getFormattedAsOfDate(),
            $columns,
            projectId: null,
            companyId: $this->company->id
        );
    }

    protected function getTransformer(ReportDTO $reportDTO): ExportableReport
    {
        return new BalanceSheetReportTransformer($reportDTO);
    }

    /**
     * Get ordered AccountTypes for Balance Sheet
     * Order: CurrentAsset → NonCurrentAsset → ContraAsset → CurrentLiability → NonCurrentLiability → ContraLiability → Equity
     */
    private function getOrderedAccountTypes(): array
    {
        return [
            AccountType::CurrentAsset,
            AccountType::NonCurrentAsset,
            AccountType::ContraAsset,
            AccountType::CurrentLiability,
            AccountType::NonCurrentLiability,
            AccountType::ContraLiability,
            AccountType::Equity,
        ];
    }

    public function buildReportEnhanced(
        ?string $template = null,
        string $format = 'pdf',
    ): array {
        $company = Company::find($this->getFilterState('company_id') ?? AuthHelper::currentCompany());
        $companyId = $company->id;

        $asOfDate = $this->getFormattedAsOfDate();
        $startDate = $this->reportService->getEarliestTransactionDate();

        $accountsWithBalance = $this->accountService->getAccountBalances(
            $startDate,
            $asOfDate,
            [],
            null,
            $companyId
        )
            ->whereIn('category', [
                AccountCategory::Asset,
                AccountCategory::Liability,
                AccountCategory::Equity,
            ])
            ->get();

        $balanceMap = [];
        foreach ($accountsWithBalance as $account) {
            $accountBalances = $this->reportService->calculateAccountBalances($account);
            $endingBalance = $accountBalances['ending_balance'] ?? $accountBalances['net_movement'] ?? 0;
            $balanceMap[$account->id] = $endingBalance;
        }

        $now = Carbon::now()->format('Y-m-d_H-i-s');
        $startFormatted = Carbon::parse($this->getFilterState('asOfDate'))->format('d M Y');

        // Nilai balance siap export: numerik/Money dipakai langsung (termasuk tanda negatif); string di-parse format Indonesia
        $toNumber = function ($v) {
            if (is_null($v)) {
                return 0.0;
            }
            if (is_int($v) || is_float($v)) {
                return (float) $v;
            }
            if ($v instanceof \App\ValueObjects\Money) {
                return (float) $v->getValue();
            }
            $str = (string) $v;
            $negative = str_starts_with($str, '-');
            $parsed = (float) str_replace(',', '.', preg_replace('/[^\d,]/', '', $str));
            return $negative ? -abs($parsed) : $parsed;
        };

        $data = [
            'filename'      => "{$company->name} Balance Sheet {$now}.pdf",
            'company_name'  => $company->name,
            'report_title'  => "Balance Sheet (Standard)",
            'report_period' => "As of {$startFormatted}",
            'columns'       => ["Description", "Balance"],
            'rows'          => [],
        ];

        $rows = collect();

        $accounts = \App\Models\Accounting\Account::where('company_id', $companyId)
            ->whereIn('category', [
                AccountCategory::Asset,
                AccountCategory::Liability,
                AccountCategory::Equity,
            ])
            ->with(['children'])
            ->orderBy('category')
            ->orderBy('type')
            ->orderBy('code')
            ->get()
            ->groupBy(['category', 'type']);

        // Recursive walker untuk hierarki akun
        $appendAccountRows = function ($accounts, $level = 1) use (&$appendAccountRows, &$rows, $toNumber, $balanceMap) {
            $padding = $level * 15;

            foreach ($accounts as $account) {
                $balance = $balanceMap[$account->id] ?? 0;
                // Skip account jika balance = 0
                if ($balance == 0) {
                    // Tetap process children jika ada (mungkin ada child dengan balance != 0)
                    if ($account->children->isNotEmpty()) {
                        $appendAccountRows($account->children, $level + 1);
                    }
                    continue;
                }

                $rows->push([
                    'data' => [
                        $account->code . ' ' . $account->name,
                        $toNumber($balance / 100),
                    ],
                    'style' => [
                        'padding-left' => "{$padding}px",
                    ],
                ]);

                if ($account->children->isNotEmpty()) {
                    $appendAccountRows($account->children, $level + 1);
                }
            }
        };

        $subtreeBalance = function ($account) use (&$subtreeBalance, $balanceMap): float {
            $balance = $balanceMap[$account->id] ?? 0;
            return $balance + $account->children->sum(fn($a) => $subtreeBalance($a));
        };

        // Loop kategori dengan urutan eksplisit: ASSET → LIABILITY → EQUITY
        $orderedCategories = [
            AccountCategory::Asset,
            AccountCategory::Liability,
            AccountCategory::Equity,
        ];

        // Mapping urutan AccountType untuk sorting
        $typeOrder = array_flip(array_map(fn($type) => $type->value, $this->getOrderedAccountTypes()));

        foreach ($orderedCategories as $category) {
            $categoryValue = $category->value;
            
            if (!isset($accounts[$categoryValue])) {
                continue; // Skip jika kategori tidak ada
            }

            $types = $accounts[$categoryValue];

            $rows->push([
                'data' => [strtoupper($categoryValue)],
                'style' => ['font-weight' => 'bold'],
            ]);

            // Sort types berdasarkan urutan yang diinginkan
            $sortedTypes = $types->sortBy(function ($accountsByType, $typeKey) use ($typeOrder) {
                return $typeOrder[$typeKey] ?? 999; // Type yang tidak ada di urutan akan di akhir
            });

            foreach ($sortedTypes as $type => $accountsByType) {
                $rows->push([
                    'data' => [str_replace('_', ' ', ucwords(strtolower($type)))],
                    'style' => [
                        'font-weight' => 'bold',
                        'padding-left' => '10px',
                    ],
                ]);

                $parentAccounts = $accountsByType->whereNull('parent_id');
                foreach ($parentAccounts as $parent) {
                    $appendAccountRows(collect([$parent]), 2);
                    $groupTotal = $subtreeBalance($parent);
                    if ($groupTotal != 0) {
                        $rows->push([
                            'data' => [
                                'Total ' . $parent->name,
                                $toNumber($groupTotal / 100),
                            ],
                            'style' => [
                                'font-weight'   => 'bold',
                                'padding-left'  => '30px',
                                'color'         => 'rgb(6, 6, 174)',
                            ],
                        ]);
                    }
                }

                // subtotal per tipe (hanya tampilkan jika bukan 0)
                $typeTotal = $accountsByType->sum(fn($a) => $balanceMap[$a->id] ?? 0);
                if ($typeTotal != 0) {
                    $rows->push([
                        'data' => [
                            "Total " . str_replace('_', ' ', ucwords(strtolower($type))),
                            $toNumber($typeTotal / 100),
                        ],
                        'style' => [
                            'font-weight'   => 'bold',
                            'padding-left'  => '10px',
                            'color'         => 'rgb(6, 6, 174)',
                        ],
                    ]);
                }
            }

            // subtotal per kategori (hanya tampilkan jika bukan 0)
            $categoryTotal = collect($types)
                ->flatten()
                ->sum(fn($a) => $balanceMap[$a->id] ?? 0);

            if ($categoryTotal != 0) {
                $rows->push([
                    'data' => [
                        "Total " . strtoupper($categoryValue),
                        $toNumber($categoryTotal / 100),
                    ],
                    'style' => [
                        'font-weight' => 'bold',
                    ],
                ]);
            }
        }

        $data['rows'] = $rows->toArray();
        return $data;
    }


    public function exportCSV(): StreamedResponse
    {
        return $this->exportService->exportToCsv($this->company, $this->report, endDate: $this->getFilterState('asOfDate'), activeTab: $this->getActiveTab());
    }

    public function exportPDF(): StreamedResponse
    {
        if ($this->getActiveTab() === 'summary') {
            return $this->exportService->exportToPdf(
                $this->company,
                $this->report,
                null,
                $this->getFilterState('asOfDate'),
                $this->getActiveTab()
            );
        }

        return $this->exportService->exportToPdfEnhanced(
            data: $this->buildReportEnhanced()
        );
    }

    public function exportXLSX(): BinaryFileResponse
    {
        if ($this->getActiveTab() === 'summary') {
            return $this->exportService->exportToExcel(
                $this->company,
                $this->report,
                null,
                $this->getFilterState('asOfDate'),
                $this->getActiveTab()
            );
        }

        return $this->exportService->exportToExcelEnhanced(
            data: $this->buildReportEnhanced(
                format: 'xlsx'
            )
        );
    }
}
