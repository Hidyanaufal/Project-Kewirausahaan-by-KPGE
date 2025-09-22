<?php

namespace App\Filament\Company\Widgets;

use App\Models\Accounting\Invoice;
use Filament\Widgets\ChartWidget;

class AgingPerClientChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Aging Report per Client';

    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        $data = Invoice::selectRaw("
                client_id,
                SUM(CASE WHEN DATEDIFF(NOW(), due_date) <= 0 THEN total - amount_paid ELSE 0 END) as current,
                SUM(CASE WHEN DATEDIFF(NOW(), due_date) BETWEEN 1 AND 30 THEN total - amount_paid ELSE 0 END) as period_1,
                SUM(CASE WHEN DATEDIFF(NOW(), due_date) BETWEEN 31 AND 60 THEN total - amount_paid ELSE 0 END) as period_2,
                SUM(CASE WHEN DATEDIFF(NOW(), due_date) BETWEEN 61 AND 90 THEN total - amount_paid ELSE 0 END) as period_3,
                SUM(CASE WHEN DATEDIFF(NOW(), due_date) > 90 THEN total - amount_paid ELSE 0 END) as over_periods
            ")
            ->with('client:id,name')
            ->groupBy('client_id')
            ->get();

        $clients = $data->pluck('client.name')->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Current',
                    'data'  => $data->pluck('current')->toArray(),
                ],
                [
                    'label' => '1-30',
                    'data'  => $data->pluck('period_1')->toArray(),
                ],
                [
                    'label' => '31-60',
                    'data'  => $data->pluck('period_2')->toArray(),
                ],
                [
                    'label' => '61-90',
                    'data'  => $data->pluck('period_3')->toArray(),
                ],
                [
                    'label' => 'Over 90',
                    'data'  => $data->pluck('over_periods')->toArray(),
                ],
            ],
            'labels' => $clients,
        ];
    }

    protected function getType(): string
    {
        return 'bar'; // bisa 'line', 'bar', 'pie', dll
    }
}
