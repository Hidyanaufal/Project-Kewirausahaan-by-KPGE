<?php

namespace App\Filament\Company\Widgets;

use App\Models\Accounting\JournalEntry;
use Filament\Widgets\ChartWidget;

class AccountMovementsChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Top Account Movements';

    protected static ?string $description = 'Highlights accounts with the largest financial activity';

    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        $startDate = now()->startOfMonth();
        $endDate   = now()->endOfMonth();

        $data = JournalEntry::selectRaw("
            accounts.category,
            accounts.name as account_name,
            SUM(journal_entries.amount) as total
        ")
            ->join('accounts', 'accounts.id', '=', 'journal_entries.account_id')
            ->join('transactions', 'transactions.id', '=', 'journal_entries.transaction_id')
            ->whereBetween('transactions.posted_at', [$startDate, $endDate])
            ->groupBy('accounts.category', 'accounts.name')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        $labels = $data->pluck('account_name')->toArray();
        $values = $data->pluck('total')->toArray();
        $colors = $data->map(function ($row) {
            return \App\Enums\Accounting\AccountCategory::from($row->category)->getColor();
        })->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Account',
                    'data' => $values,
                    'borderColor' => $colors,
                    'backgroundColor' => $colors,
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
