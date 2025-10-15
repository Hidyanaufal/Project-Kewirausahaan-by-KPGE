<?php

namespace App\Filament\Company\Resources\Addons;

use App\Concerns\HasPermissionCheck;
use App\Filament\Company\Resources\Addons\DepreciationRecordResource\Pages;
use App\Models\Addons\Asset;
use App\Models\Addons\DepreciationRecord;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
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
                Forms\Components\Section::make('Depreciation Details')
                    ->description('Record the fiscal depreciation for an asset. Normally generated automatically from the Depreciation engine.')
                    ->schema([
                        Forms\Components\Select::make('asset_id')
                            ->label('Asset')
                            ->options(fn() => Asset::query()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Select the asset this depreciation record belongs to.'),

                        Forms\Components\TextInput::make('fiscal_year')
                            ->label('Fiscal Year')
                            ->numeric()
                            ->default(now()->year)
                            ->required()
                            ->helperText('The tax/financial year of this depreciation record. Defaults to the current year.'),

                        Forms\Components\TextInput::make('opening_fiscal_book_value')
                            ->label('Opening Book Value')
                            ->money()
                            ->required()
                            ->helperText('Book value at the beginning of the fiscal year (after prior years’ depreciation).'),

                        Forms\Components\TextInput::make('depreciation_expense')
                            ->label('Depreciation Expense (This Year)')
                            ->required()
                            ->money()
                            ->helperText('Total depreciation expense recognized for this fiscal year.')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                $opening = (float) $get('opening_fiscal_book_value');
                                $expense = (float) $state;
                                $closing = max(0, round($opening - $expense, 2));
                                $set('closing_fiscal_book_value', $closing);
                            }),

                        Forms\Components\TextInput::make('closing_fiscal_book_value')
                            ->label('Closing Book Value')
                            ->money()
                            ->required()
                            ->helperText('Book value at the end of the fiscal year. Auto-filled as Opening − Expense.')
                            ->disabled(fn(Get $get) => filled($get('opening_fiscal_book_value')) && filled($get('depreciation_expense')))
                            ->dehydrated(), // still save the value even if disabled

                        Forms\Components\TextInput::make('commercial_method')
                            ->label('Commercial Method')
                            ->maxLength(50)
                            ->helperText('Optional: method used for commercial accounting (if different from fiscal).'),

                        Forms\Components\TextInput::make('fiscal_method')
                            ->label('Fiscal Method')
                            ->maxLength(50)
                            ->helperText('Method used for tax/fiscal depreciation (e.g., Straight Line, Declining Balance).'),

                        Forms\Components\KeyValue::make('monthly_breakdown')
                            ->label('Monthly Breakdown')
                            ->keyLabel('Month (1–12)')
                            ->valueLabel('Expense')
                            ->helperText('Optional detail per month. Keys are 1..12; values are monthly expense amounts.'),
                    ])
                    ->columns(2),
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
                    ->options(
                        collect(range((int) now()->year, (int) now()->year - 10))
                            ->mapWithKeys(fn($y) => [$y => (string) $y])
                            ->toArray()
                    ),
                Tables\Filters\SelectFilter::make('asset_id')
                    ->label('Asset')
                    ->options(fn() => Asset::query()->orderBy('name')->pluck('name', 'id')),
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
