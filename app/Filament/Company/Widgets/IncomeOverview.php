<?php

namespace App\Filament\Company\Widgets;

use App\Concerns\HasPermissionCheck;
use App\Services\ReportService;
use App\Transformers\IncomeStatementReportTransformer;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class IncomeOverview extends BaseWidget
{
    use HasPermissionCheck;

    protected function getColumns(): int
    {
        return 2;
    }

    protected function getStats(): array
    {
        $reportService = app(ReportService::class);
        $startDate = now()->startOfMonth()->toDateString();
        $endDate   = now()->endOfMonth()->toDateString();
        $reportDTO = $reportService->buildIncomeStatementReport($startDate, $endDate, [
            'account_code',
            'account_name',
            'net_movement'
        ]);

        $transformer = new IncomeStatementReportTransformer($reportDTO);
        $summary = $transformer->getSummary();

        return [
            Stat::make('Revenue', $summary[0]['value'] ?? 'Rp0,00')
                ->icon('heroicon-o-currency-dollar')
                ->color($summary[0]['value'] > 0 ? 'success' : 'danger')
                ->description('Total Revenue'),

            Stat::make('COGS', $summary[1]['value'] ?? 'Rp0,00')
                ->icon('heroicon-o-shopping-cart')
                ->color($summary[1]['value'] > 0 ? 'success' : 'danger')
                ->description('Cost of Goods Sold'),

            Stat::make('Expenses', $summary[2]['value'] ?? 'Rp0,00')
                ->icon('heroicon-o-banknotes')
                ->color($summary[2]['value'] > 0 ? 'success' : 'danger')
                ->description('Operating Expenses'),

            Stat::make('Net Earnings', $summary[3]['value'] ?? 'Rp0,00')
                ->icon('heroicon-o-chart-bar')
                ->color($summary[3]['value'] > 0 ? 'success' : 'danger')
                ->description('Final Net Earnings'),
        ];
    }
}
