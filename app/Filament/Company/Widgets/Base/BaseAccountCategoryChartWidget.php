<?php

namespace App\Filament\Company\Widgets\Base;

use App\Models\Accounting\JournalEntry;
use Filament\Widgets\ChartWidget;
use App\Enums\Accounting\AccountCategory;

abstract class BaseAccountCategoryChartWidget extends ChartWidget
{
    protected AccountCategory $category;

    protected int | string | array $columnSpan = 2;

    protected function getData(): array
    {
        $startDate = now()->startOfMonth();
        $endDate   = now()->endOfMonth();

        // Query total per akun berdasarkan category (Asset, Liability, etc.)
        $data = JournalEntry::selectRaw("
                accounts.name as account_name,
                SUM(journal_entries.amount) as total
            ")
            ->join('accounts', 'accounts.id', '=', 'journal_entries.account_id')
            ->join('transactions', 'transactions.id', '=', 'journal_entries.transaction_id')
            ->whereBetween('transactions.posted_at', [$startDate, $endDate])
            ->where('accounts.category', '=', $this->category->value)
            ->groupBy('accounts.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

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
        return 'bar'; // bisa diubah jadi pie kalau subclass butuh
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
        ];
    }
}
