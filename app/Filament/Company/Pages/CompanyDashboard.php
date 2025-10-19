<?php

namespace App\Filament\Company\Pages;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use App\Filament\Company\Widgets;
use App\Filament\Forms\Addons\FilterProjectCompany;

class CompanyDashboard extends Dashboard
{
    use \App\Concerns\HasPermissionCheck;
    use HasFiltersForm;

    protected static string $routePath = '/';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    public function getColumns(): int | array
    {
        return [
            'sm' => 1,
            'md' => 4,
        ];
    }

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('start_date')
                ->label('Start Date')
                ->native(true)
                ->default(now()->startOfMonth()),
            DatePicker::make('end_date')
                ->label('End Date')
                ->native(true)
                ->default(now()->endOfMonth()),
            ...FilterProjectCompany::make()
        ]);
    }

    public function getWidgets(): array
    {
        return [
            Widgets\IncomeOverview::class,
            Widgets\AgingPerClientChartWidget::class,
            Widgets\CollectionsTrendChartWidget::class,
            Widgets\AccountMovementsChartWidget::class,
            Widgets\RevenueBreakdownByCategory::class,
            Widgets\ExpenseBreakdownByCategory::class,
            Widgets\AssetBreakdownByCategory::class,
            Widgets\EquityBreakdownByCategory::class,
        ];
    }
}
