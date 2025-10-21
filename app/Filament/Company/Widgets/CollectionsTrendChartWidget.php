<?php

namespace App\Filament\Company\Widgets;

use App\Concerns\HasPermissionCheck;
use App\Helpers\AuthHelper;
use App\Models\Accounting\Invoice;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class CollectionsTrendChartWidget extends ChartWidget implements HasForms
{
    use HasPermissionCheck;
    use InteractsWithPageFilters;
    use InteractsWithForms;

    protected static ?string $heading = 'Collections Performance';

    protected static ?string $description = 'Indicates the effectiveness of the finance team in collecting invoices monthly.';

    protected int|string|array $columnSpan = 2;

    protected function getData(): array
    {
        $query = Invoice::selectRaw("
            DATE_FORMAT(paid_at, '%Y-%m') as month,
            SUM(amount_paid) / 100 as total_collections
        ")
            ->whereNotNull('paid_at')
            ->groupBy('month')
            ->orderBy('month');

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

        $data = $query->pluck('total_collections', 'month');

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
