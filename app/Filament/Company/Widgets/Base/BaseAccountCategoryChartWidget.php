<?php

namespace App\Filament\Company\Widgets\Base;

use App\Concerns\HasPermissionCheck;
use App\Models\Accounting\JournalEntry;
use Filament\Widgets\ChartWidget;
use App\Enums\Accounting\AccountCategory;
use App\Helpers\AuthHelper;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

abstract class BaseAccountCategoryChartWidget extends ChartWidget implements HasForms
{
    use HasPermissionCheck;
    use InteractsWithPageFilters;
    use InteractsWithForms;

    protected AccountCategory $category;

    protected int | string | array $columnSpan = 2;

    protected function getData(): array
    {
        $startDate = $this->filters['start_date'] ?? now()->startOfYear()->toDateString();
        $endDate   = $this->filters['end_date'] ?? now()->endOfMonth()->toDateString();

        // Query total per akun berdasarkan category (Asset, Liability, etc.)
        $query = JournalEntry::selectRaw("
                accounts.name as account_name,
                SUM(journal_entries.amount) as total
            ")
            ->join('accounts', 'accounts.id', '=', 'journal_entries.account_id')
            ->join('transactions', 'transactions.id', '=', 'journal_entries.transaction_id')
            ->whereBetween('transactions.posted_at', [$startDate, $endDate])
            ->where('accounts.category', '=', $this->category->value)
            ->groupBy('accounts.name')
            ->orderByDesc('total')
            ->limit(10);

        if (AuthHelper::isPrimaryCompany()) {
            $query->withoutGlobalScopes();
        }

        if ($this->filters['company_id']) {
            $query->where('accounts.company_id', $this->filters['company_id']);
        }

        $data = $query->get();
        $labels = $data->pluck('account_name')->toArray();
        $values = $data->pluck('total')->toArray();
        $colors = $data->map(fn() => $this->category->getColor())->toArray();

        return [
            'datasets' => [
                [
                    'label' => $this->category->getPluralLabel(),
                    'data' => $values,
                    'backgroundColor' => $colors,
                    'borderColor' => $colors,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => ['beginAtZero' => true],
            ],
            'elements' => [
                'bar' => [
                    'borderWidth' => 0,
                ],
            ],
        ];
    }
}
