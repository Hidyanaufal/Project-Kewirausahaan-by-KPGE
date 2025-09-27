<?php

namespace App\Filament\Company\Widgets;

use App\Enums\Accounting\AccountCategory;
use App\Filament\Company\Widgets\Base\BaseAccountCategoryChartWidget;

class EquityBreakdownByCategory extends BaseAccountCategoryChartWidget
{
    protected static ?string $heading = 'Equity Breakdown by Category';
    protected static ?string $description = 'Shows how equity is distributed across different accounts.';
    protected AccountCategory $category = AccountCategory::Equity;
}
