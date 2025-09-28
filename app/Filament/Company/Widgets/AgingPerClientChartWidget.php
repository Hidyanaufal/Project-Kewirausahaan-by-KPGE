<?php

namespace App\Filament\Company\Widgets;

use App\Models\Accounting\Invoice;
use Filament\Widgets\ChartWidget;

class AgingPerClientChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Aging Receivables';

    protected static ?string $description = 'Breaks down outstanding invoices by aging periods for each client.';

    protected int | string | array $columnSpan = 2;

    protected function getData(): array
    {
        $data = Invoice::selectRaw("
                client_id,
                SUM(CASE WHEN DATEDIFF(NOW(), due_date) <= 0 THEN (total - amount_paid) / 100 ELSE 0 END) as current,
                SUM(CASE WHEN DATEDIFF(NOW(), due_date) BETWEEN 1 AND 30 THEN (total - amount_paid) / 100 ELSE 0 END) as period_1,
                SUM(CASE WHEN DATEDIFF(NOW(), due_date) BETWEEN 31 AND 60 THEN (total - amount_paid) / 100 ELSE 0 END) as period_2,
                SUM(CASE WHEN DATEDIFF(NOW(), due_date) BETWEEN 61 AND 90 THEN (total - amount_paid) / 100 ELSE 0 END) as period_3,
                SUM(CASE WHEN DATEDIFF(NOW(), due_date) > 90 THEN (total - amount_paid) / 100 ELSE 0 END) as over_periods
            ")
            ->with('client:id,name')
            ->groupBy('client_id')
            ->get();

        $clients = $data->pluck('client.name')->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Current',
                    'data' => $data->pluck('current')->toArray(),
                    'backgroundColor' => '#4CAF50', // hijau → masih bisa ditagih
                    'borderColor' => '#4CAF50',
                ],
                [
                    'label' => '1-30',
                    'data' => $data->pluck('period_1')->toArray(),
                    'backgroundColor' => '#FFC107', // kuning → mulai overdue
                    'borderColor' => '#FFC107',
                ],
                [
                    'label' => '31-60',
                    'data' => $data->pluck('period_2')->toArray(),
                    'backgroundColor' => '#FF9800', // oranye
                    'borderColor' => '#FF9800',
                ],
                [
                    'label' => '61-90',
                    'data' => $data->pluck('period_3')->toArray(),
                    'backgroundColor' => '#F44336', // merah
                    'borderColor' => '#F44336',
                ],
                [
                    'label' => 'Over 90',
                    'data' => $data->pluck('over_periods')->toArray(),
                    'backgroundColor' => '#B71C1C', // merah tua
                    'borderColor' => '#B71C1C',
                ],
            ],
            'labels' => $clients,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true],
            ],
        ];
    }
}
