<?php

namespace App\Filament\Company\Widgets;

use App\Enums\Accounting\AccountCategory;
use App\Filament\Company\Widgets\Base\BaseAccountCategoryChartWidget;

class ExpenseBreakdownByCategory extends BaseAccountCategoryChartWidget
{
    protected static ?string $heading = 'Expense Breakdown by Category';
    protected static ?string $description = 'Shows which expense categories contribute most to overall spending.';
    protected AccountCategory $category = AccountCategory::Expense;
}
