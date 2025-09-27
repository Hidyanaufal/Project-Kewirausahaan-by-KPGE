<?php

namespace App\Filament\Company\Widgets;

use App\Enums\Accounting\AccountCategory;
use App\Filament\Company\Widgets\Base\BaseAccountCategoryChartWidget;

class AssetBreakdownByCategory extends BaseAccountCategoryChartWidget
{
    protected static ?string $heading = 'Asset Breakdown by Category';
    protected static ?string $description = 'Shows which asset accounts hold the largest balances.';

    protected AccountCategory $category = AccountCategory::Asset;
}
