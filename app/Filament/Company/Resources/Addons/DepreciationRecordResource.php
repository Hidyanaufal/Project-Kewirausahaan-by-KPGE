<?php

namespace App\Filament\Company\Resources\Addons;

use App\Concerns\HasPermissionCheck;
use App\Filament\Company\Resources\Addons\DepreciationRecordResource\Pages;
use App\Filament\Exports\Accounting\DepreciationRecordExporter;
use App\Helpers\AuthHelper;
use App\Models\Addons\Asset;
use App\Models\Addons\DepreciationRecord;
use App\Models\Company;
use App\Services\ExportService;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;

class DepreciationRecordResource extends Resource
{
    use HasPermissionCheck;

    protected static ?string $model = DepreciationRecord::class;

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->columns(1)
            ->schema([
                Forms\Components\Select::make('asset_id')
                    ->label('Asset')
                    ->options(fn() => Asset::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\TextInput::make('fiscal_year')
                    ->label('Fiscal Year')
                    ->numeric()
                    ->default(now()->year)
                    ->required(),

                Forms\Components\TextInput::make('opening_fiscal_book_value')
                    ->label('Opening Book Value')
                    ->money()
                    ->required(),

                Forms\Components\TextInput::make('closing_fiscal_book_value')
                    ->label('Closing Book Value')
                    ->money()
                    ->required()
                    ->disabled(fn(Get $get) => filled($get('opening_fiscal_book_value')) && filled($get('depreciation_expense')))
                    ->dehydrated(),
            ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('asset.name')
                    ->label('Asset')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('fiscal_year')
                    ->label('Fiscal Year')
                    ->sortable(),

                Tables\Columns\TextColumn::make('opening_fiscal_book_value')
                    ->label('Opening Book Value')
                    ->currency(),

                Tables\Columns\TextColumn::make('depreciation_expense')
                    ->label('Depreciation Expense')
                    ->currency(),

                Tables\Columns\TextColumn::make('closing_fiscal_book_value')
                    ->label('Closing Book Value')
                    ->currency(),

                Tables\Columns\TextColumn::make('fiscal_method')
                    ->label('Fiscal Method')
                    ->badge(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('fiscal_year')
                    ->label('Fiscal Year')
                    ->searchable()
                    ->options(
                        collect(range((int) now()->year, (int) now()->year - 10))
                            ->mapWithKeys(fn($y) => [$y => (string) $y])
                            ->toArray()
                    ),
                Tables\Filters\SelectFilter::make('asset_id')
                    ->searchable()
                    ->label('Asset')
                    ->options(fn() => Asset::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->headerActions([
                Tables\Actions\Action::make('exportExcel')
                    ->label('Export XLSX')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(function () {
                        $company = Company::find(AuthHelper::currentCompany());

                        $records = \App\Models\Addons\DepreciationRecord::with('asset.group')
                            ->where('company_id', $company->id)
                            ->orderBy('fiscal_year', 'desc')
                            ->get();

                        $now = now()->format('Y-m-d_H-i-s');

                        $header = [
                            'No',
                            'Kelompok / Jenis Harta',
                            'Bulan / Tahun Perolehan',
                            'Harga Perolehan (Rp)',
                            'Nilai Sisa Buku Fiskal Awal Tahun (Rp)',
                            'Metode Komersial',
                            'Metode Fiskal',
                            'Penyusutan / Amortisasi Fiskal Tahun Ini (Rp)',
                            'Catatan (Nilai Sisa Buku)',
                        ];

                        $rows = collect();

                        $rowNumber = 1;

                        $grouped = $records->groupBy(fn($r) => $r->asset->group->name ?? 'Lain-lain');

                        foreach ($grouped as $groupName => $groupRecords) {
                            // Header per kategori
                            $rows->push([
                                'data' => [$groupName],
                                'style' => [
                                    'bold' => true,
                                    'underline' => true,
                                ],
                            ]);

                            // Detail tiap record
                            foreach ($groupRecords as $i => $record) {
                                $rows->push([
                                    'data' => [
                                        $rowNumber++,
                                        $record->asset->name ?? '-',
                                        optional($record->asset->acquired_at)->format('d M Y') ?? '-',
                                        $record->asset->purchase_price / 100,
                                        $record->opening_fiscal_book_value / 100,
                                        $record->commercial_method?->getLabel() ?? '-',
                                        $record->fiscal_method?->getLabel() ?? '-',
                                        $record->depreciation_expense / 100,
                                        $record->closing_fiscal_book_value / 100,
                                    ],
                                    'style' => [],
                                ]);
                            }

                            // Subtotal per kategori
                            $rows->push([
                                'data' => [
                                    '',
                                    "Subtotal {$groupName}",
                                    '',
                                    '',
                                    '',
                                    '',
                                    '',
                                    $groupRecords->sum('depreciation_expense') / 100,
                                    '',
                                ],
                                'style' => ['bold' => true],
                            ]);

                            // Spacer kosong antar grup
                            $rows->push([
                                'data' => [''],
                                'style' => [],
                            ]);
                        }

                        // Grand total
                        $rows->push([
                            'data' => [
                                '',
                                'JUMLAH PENYUSUTAN FISKAL',
                                '',
                                '',
                                '',
                                '',
                                '',
                                $records->sum('depreciation_expense') / 100,
                                '',
                            ],
                            'style' => ['bold' => true, 'border-top' => true],
                        ]);

                        $data = [
                            'filename'      => "{$company->name} Daftar Penyusutan {$now}.xlsx",
                            'company_name'  => $company->name,
                            'report_title'  => 'Daftar Penyusutan dan Amortisasi Fiskal',
                            'report_period' => 'As of ' . now()->format('d M Y'),
                            'columns'       => $header,
                            'rows'          => $rows->toArray(),
                        ];

                        $exportService = new \App\Services\ExportService();

                        return $exportService->exportToExcelEnhanced(data: $data);
                    }),
                Tables\Actions\Action::make('exportPdf')
                    ->label('Export PDF')
                    ->color('danger')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(function () {
                        $companyId = AuthHelper::currentCompany();
                        $company = Company::find($companyId);
                        $records = \App\Models\Addons\DepreciationRecord::with('asset')
                            ->where('company_id', $companyId)
                            ->get();

                        $now = Carbon::now()->format('Y-m-d_H-i-s');
                        $filename = "{$company->name} Daftar Penyusutan {$now}.pdf";
                        $view = 'pdf.depreciation-records';

                        $pdf = SnappyPdf::loadView($view, [
                            'company' => $company,
                            'records' => $records,
                            'report' => [
                                'fiscal_year' => now()->year,
                            ],
                        ]);

                        $pdf->setOptions([
                            'dpi' => 96,
                            'zoom' => 1,
                            'enable-local-file-access' => true,
                            'encoding' => 'UTF-8',
                            'margin-top' => 5,
                            'margin-bottom' => 5,
                            'margin-left' => 5,
                            'margin-right' => 5,
                            'disable-smart-shrinking' => true,
                            'orientation' => 'Landscape'
                        ]);

                        return response()->streamDownload(
                            fn() => print($pdf->output()),
                            $filename
                        );
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->slideOver()
                        ->modalWidth(\Filament\Support\Enums\MaxWidth::Small),
                ])
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageDepreciationRecords::route('/'),
        ];
    }
}
