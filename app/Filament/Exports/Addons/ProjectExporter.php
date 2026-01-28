<?php

namespace App\Filament\Exports\Addons;

use App\Models\Addons\Project;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class ProjectExporter extends Exporter
{
    protected static ?string $model = Project::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('number'),
            ExportColumn::make('name')
                ->label('Project Name'),
            ExportColumn::make('type.name')
                ->label('Type'),
            ExportColumn::make('client.name')
                ->label('Client'),
            ExportColumn::make('pic.name')
                ->label('PIC'),
            ExportColumn::make('request_number_date')
                ->label('Request Date')
                ->date(),
            ExportColumn::make('status')
                ->enum(),
            ExportColumn::make('project_value')
                ->label('Value')
                ->money(),
            ExportColumn::make('start_date')
                ->label('Start Date')
                ->date(),
            ExportColumn::make('end_date')
                ->label('End Date')
                ->date(),
            ExportColumn::make('description')
                ->enabledByDefault(false),
            ExportColumn::make('work_basis')
                ->label('Basis of Work (Work Order/Contract)')
                ->enabledByDefault(false),
            ExportColumn::make('pic_client_name')
                ->label('PIC Client')
                ->enabledByDefault(false),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your project export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
