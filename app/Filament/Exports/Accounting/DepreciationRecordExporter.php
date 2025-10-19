<?php

namespace App\Filament\Exports\Accounting;

use App\Models\Addons\DepreciationRecord;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class DepreciationRecordExporter extends Exporter
{
    protected static ?string $model = DepreciationRecord::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('asset.name')
                ->label('Asset Name'),
            ExportColumn::make('fiscal_year')
                ->label('Fiscal Year'),
            ExportColumn::make('opening_fiscal_book_value')
                ->label('Opening Book Value')
                ->money(),
            ExportColumn::make('depreciation_expense')
                ->label('Depreciation Expense')
                ->money(),
            ExportColumn::make('closing_fiscal_book_value')
                ->label('Closing Book Value')
                ->money(),
            ExportColumn::make('fiscal_method')
                ->label('Fiscal Method'),
            ExportColumn::make('company.name')
                ->label('Company')
                ->enabledByDefault(false),
            ExportColumn::make('created_at')
                ->label('Created At')
                ->dateTime()
                ->enabledByDefault(false),
            ExportColumn::make('updated_at')
                ->label('Updated At')
                ->dateTime()
                ->enabledByDefault(false),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your depreciation record export has completed and ' .
            number_format($export->successful_rows) . ' ' .
            str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' .
                str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
