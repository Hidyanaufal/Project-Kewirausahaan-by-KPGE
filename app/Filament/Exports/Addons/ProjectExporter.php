<?php

namespace App\Filament\Exports\Addons;

use App\Filament\Exports\BaseExporter;
use App\Models\Addons\Project;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Models\Export;

class ProjectExporter extends BaseExporter
{
    protected static ?string $model = Project::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('number'),
            ExportColumn::make('name')
                ->label('Project Name')
                ->formatStateUsing(static fn (?string $state) => $state ? preg_replace("/\s+/", ' ', trim($state)) : $state),
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
                ->state(fn (Project $record) => self::excelMoney($record->project_value)),
            ExportColumn::make('start_date')
                ->label('Start Date')
                ->date(),
            ExportColumn::make('end_date')
                ->label('End Date')
                ->date(),
            ExportColumn::make('description')
                ->enabledByDefault(false)
                ->formatStateUsing(static fn (?string $state) => $state ? preg_replace("/\s+/", ' ', trim($state)) : $state)
                ->words(12),
            ExportColumn::make('work_basis')
                ->label('Basis of Work (Work Order/Contract)')
                ->enabledByDefault(false)
                ->formatStateUsing(static fn (?string $state) => $state ? preg_replace("/\s+/", ' ', trim($state)) : $state)
                ->words(12),
            ExportColumn::make('pic_client_name')
                ->label('PIC Client')
                ->enabledByDefault(false)
                ->formatStateUsing(static fn (?string $state) => $state ? preg_replace("/\s+/", ' ', trim($state)) : $state),

            ExportColumn::make('approved_invoice_total_without_ppn_sum')
                ->label('Total Invoice (Tanpa PPN)')
                ->getStateUsing(static fn (Project $record): int => $record->total_invoice_without_ppn)
                ->state(fn (Project $record) => self::excelMoney($record->total_invoice_without_ppn)),
            ExportColumn::make('approved_bill_total_sum')
                ->label('Total Biaya')
                ->getStateUsing(static fn (Project $record): int => $record->total_biaya)
                ->state(fn (Project $record) => self::excelMoney($record->total_biaya)),
            ExportColumn::make('net_earning')
                ->label('Net Earning')
                ->getStateUsing(static fn (Project $record): int => $record->net_earning)
                ->state(fn (Project $record) => self::excelMoney($record->net_earning)),
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
