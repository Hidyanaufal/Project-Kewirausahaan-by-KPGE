<?php

namespace App\Filament\Company\Widgets;

use App\Models\Accounting\Invoice;
use Filament\Widgets\ChartWidget;

class CollectionsTrendChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Collections Performance';

    protected static ?string $description = 'Indicates the effectiveness of the finance team in collecting invoices monthly.';

    protected int|string|array $columnSpan = 2;

    protected function getData(): array
    {
        $data = Invoice::selectRaw("
                DATE_FORMAT(paid_at, '%Y-%m') as month,
                SUM(amount_paid) as total_collections
            ")
            ->whereNotNull('paid_at')
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total_collections', 'month');

        return [
            'datasets' => [
                [
                    'label' => 'Collections',
                    'data' => $data->values()->toArray(),
                    'backgroundColor' => '#4CAF50',
                    'borderColor' => '#2E7D32',
                ],
            ],
            'labels' => $data->keys()->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => true],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'title' => ['display' => true, 'text' => 'Amount Collected'],
                ],
                'x' => [
                    'title' => ['display' => true, 'text' => 'Month'],
                ],
            ],
        ];
    }
}
