<?php

namespace App\Filament\Company\Widgets;

use App\Concerns\HasPermissionCheck;
use App\Helpers\AuthHelper;
use App\Models\Accounting\Invoice;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class AgingPerClientChartWidget extends ChartWidget implements HasForms
{
    use HasPermissionCheck;
    use InteractsWithPageFilters;
    use InteractsWithForms;

    protected static ?string $heading = 'Aging Receivables';

    protected static ?string $description = 'Breaks down outstanding invoices by aging periods for each client.';

    protected int | string | array $columnSpan = 2;

    protected function getData(): array
    {
        $query = Invoice::selectRaw("
                client_id,
                SUM(CASE WHEN DATEDIFF(NOW(), due_date) <= 0 THEN (total - amount_paid) / 100 ELSE 0 END) as current,
                SUM(CASE WHEN DATEDIFF(NOW(), due_date) BETWEEN 1 AND 30 THEN (total - amount_paid) / 100 ELSE 0 END) as period_1,
                SUM(CASE WHEN DATEDIFF(NOW(), due_date) BETWEEN 31 AND 60 THEN (total - amount_paid) / 100 ELSE 0 END) as period_2,
                SUM(CASE WHEN DATEDIFF(NOW(), due_date) BETWEEN 61 AND 90 THEN (total - amount_paid) / 100 ELSE 0 END) as period_3,
                SUM(CASE WHEN DATEDIFF(NOW(), due_date) > 90 THEN (total - amount_paid) / 100 ELSE 0 END) as over_periods
            ")
            ->with('client:id,name')
            ->where('status', '!=', 'paid')
            ->groupBy('client_id');

        if (AuthHelper::isPrimaryCompany()) {
            $query->withoutGlobalScopes();
        }

        if (!empty($this->filters['company_id'])) {
            $companyId = $this->filters['company_id'];

            $query->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->orWhereHas('client', function ($sub) use ($companyId) {
                        $sub->where('company_id', $companyId);
                    });
            });
        }

        $data = $query->get();
        $clients = $data->pluck('client.name')->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Current',
                    'data' => $data->pluck('current')->toArray(),
                    'backgroundColor' => '#4CAF50', // hijau → masih bisa ditagih
                ],
                [
                    'label' => '1-30',
                    'data' => $data->pluck('period_1')->toArray(),
                    'backgroundColor' => '#FFC107', // kuning → mulai overdue
                ],
                [
                    'label' => '31-60',
                    'data' => $data->pluck('period_2')->toArray(),
                    'backgroundColor' => '#FF9800', // oranye
                ],
                [
                    'label' => '61-90',
                    'data' => $data->pluck('period_3')->toArray(),
                    'backgroundColor' => '#F44336', // merah
                ],
                [
                    'label' => 'Over 90',
                    'data' => $data->pluck('over_periods')->toArray(),
                    'backgroundColor' => '#B71C1C', // merah tua
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
            'elements' => [
                'bar' => [
                    'borderWidth' => 0,
                ],
            ],
            'animation' => [
                'duration' => 1000,
                'easing' => 'easeInOutQuart',
            ],
        ];
    }
}
