<?php

namespace App\Filament\Company\Widgets;

use Filament\Widgets\ChartWidget;

class IncomeTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Financial Performance Overview';

    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
        $revenue = [12000000, 15000000, 18000000, 20000000, 22000000, 25000000];
        $cogs = [5000000, 6000000, 7000000, 8000000, 9000000, 9500000];
        $expenses = [3000000, 3500000, 4000000, 4200000, 4500000, 4700000];
        $netEarnings = [4000000, 5500000, 7000000, 7800000, 8500000, 10800000];

        return [
            'datasets' => [
                [
                    'label' => 'Revenue',
                    'data' => $revenue,
                    'borderColor' => '#4CAF50',
                    'backgroundColor' => 'rgba(76, 175, 80, 0.2)',
                    'fill' => true,
                    'tension' => 0.4,
                ],
                [
                    'label' => 'COGS',
                    'data' => $cogs,
                    'borderColor' => '#F44336',
                    'backgroundColor' => 'rgba(244, 67, 54, 0.2)',
                    'fill' => true,
                    'tension' => 0.4,
                ],
                [
                    'label' => 'Expenses',
                    'data' => $expenses,
                    'borderColor' => '#FFC107',
                    'backgroundColor' => 'rgba(255, 193, 7, 0.2)',
                    'fill' => true,
                    'tension' => 0.4,
                ],
                [
                    'label' => 'Net Earnings',
                    'data' => $netEarnings,
                    'borderColor' => '#2196F3',
                    'backgroundColor' => 'rgba(33, 150, 243, 0.2)',
                    'fill' => true,
                    'tension' => 0.4,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
