<?php

namespace App\Filament\Company\Widgets;

use App\Concerns\HasPermissionCheck;
use App\Helpers\AuthHelper;
use App\Services\ReportService;
use App\Transformers\IncomeStatementReportTransformer;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class IncomeOverview extends BaseWidget implements HasForms
{
    use HasPermissionCheck;
    use InteractsWithPageFilters;
    use InteractsWithForms;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $startDate = $this->filters['start_date'] ?? now()->startOfYear()->toDateString();
        $endDate   = $this->filters['end_date'] ?? now()->endOfMonth()->toDateString();

        $reportService = app(ReportService::class);
        $reportDTO = $reportService->buildIncomeStatementReport(
            $startDate,
            $endDate,
            [
                'account_code',
                'account_name',
                'net_movement',
            ],
            projectId: $this->filters['project_id'] ?? null,
            companyId: AuthHelper::isPrimaryCompany() ? $this->filters['company_id'] : null,
            projectTypeId: $this->filters['project_type_id'] ?? null,
        );

        $transformer = new IncomeStatementReportTransformer($reportDTO);
        $summary = $transformer->getSummary();

        return [
            Stat::make('Revenue', formatCurrencyShort($summary[0]['value']) ?? 'Rp0,00')
                ->icon('heroicon-o-currency-dollar')
                ->color($summary[0]['value'] > 0 ? 'success' : 'danger')
                ->description('Total Revenue'),

            Stat::make('COGS', formatCurrencyShort($summary[1]['value']) ?? 'Rp0,00')
                ->icon('heroicon-o-shopping-cart')
                ->color($summary[1]['value'] > 0 ? 'success' : 'danger')
                ->description('Cost of Goods Sold'),

            Stat::make('Expenses', formatCurrencyShort($summary[2]['value']) ?? 'Rp0,00')
                ->icon('heroicon-o-banknotes')
                ->color($summary[2]['value'] > 0 ? 'success' : 'danger')
                ->description('Operating Expenses'),

            Stat::make('Net Earnings', formatCurrencyShort($summary[3]['value']) ?? 'Rp0,00')
                ->icon('heroicon-o-chart-bar')
                ->color($summary[3]['value'] > 0 ? 'success' : 'danger')
                ->description('Final Net Earnings'),
        ];
    }
}
