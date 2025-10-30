<?php

namespace App\Filament\Company\Resources\Addons;

use App\Concerns\HasPermissionCheck;
use App\Filament\Company\Resources\Addons\DepreciationRecordResource\Pages;
use App\Filament\Exports\Accounting\DepreciationRecordExporter;
use App\Helpers\AuthHelper;
use App\Models\Addons\Asset;
use App\Models\Addons\DepreciationRecord;
use App\Models\Company;
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
                Tables\Actions\ExportAction::make()
                    ->label('Export XLSX')
                    ->icon('heroicon-o-document-arrow-down')
                    ->exporter(DepreciationRecordExporter::class),
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
