<?php

namespace App\Filament\Company\Widgets;

use App\Enums\Accounting\AccountCategory;
use App\Filament\Company\Widgets\Base\BaseAccountCategoryChartWidget;

class RevenueBreakdownByCategory extends BaseAccountCategoryChartWidget
{
    protected static ?string $heading = 'Revenue Breakdown by Category';
    protected static ?string $description = 'Shows which revenue sources generate the most income.';
    protected AccountCategory $category = AccountCategory::Revenue;
}
