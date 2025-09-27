<?php

namespace App\Filament\Company\Widgets;

use App\Models\Accounting\Invoice;
use Filament\Widgets\ChartWidget;

class TopClientsOutstandingWidget extends ChartWidget
{
    protected static ?string $heading = 'Top 5 Clients by Outstanding Balance';

    protected int | string | array $columnSpan = 2;

    protected function getData(): array
    {
        $data = Invoice::selectRaw("
                client_id,
                SUM(total - amount_paid) as outstanding
            ")
            ->with('client:id,name')
            ->groupBy('client_id')
            ->orderByDesc('outstanding')
            ->limit(5)
            ->get();

        $clients = $data->pluck('client.name')->toArray();
        $outstandings = $data->pluck('outstanding')->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Outstanding',
                    'data' => $outstandings,
                    'backgroundColor' => '#F44336',
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
            'indexAxis' => 'y', // bikin bar horizontal
            'plugins' => [
                'legend' => ['display' => false], // legend ga terlalu perlu
            ],
            'scales' => [
                'x' => [
                    'beginAtZero' => true,
                ],
            ],
        ];
    }
}
