<?php

namespace App\Filament\Company\Resources\Addons;

use App\Concerns\HasPermissionCheck;
use App\Filament\Company\Resources\Addons\DepreciationRecordResource\Pages;
use App\Filament\Exports\Accounting\DepreciationRecordExporter;
use App\Models\Addons\Asset;
use App\Models\Addons\DepreciationRecord;
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
                    ->label('Fiscal Method'),

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
                    ->exporter(DepreciationRecordExporter::class),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->slideOver()
                    ->modalWidth(\Filament\Support\Enums\MaxWidth::ThreeExtraLarge),
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
