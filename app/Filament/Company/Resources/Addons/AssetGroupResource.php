<?php

namespace App\Filament\Company\Resources\Addons;

use App\Concerns\HasPermissionCheck;
use App\Enums\Addons\DepreciationMethod;
use App\Filament\Company\Resources\Addons\AssetGroupResource\Pages;
use App\Models\Addons\AssetGroup;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;

class AssetGroupResource extends Resource
{
    use HasPermissionCheck;

    protected static ?string $model = AssetGroup::class;

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Section::make('Asset Group')
                ->description('Define default depreciation behavior for assets under this group.')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Group Name')
                        ->required()
                        ->maxLength(255)
                        ->helperText('A short, descriptive name. Example: “Office Equipment”, “Vehicles”.'),

                    Forms\Components\Textarea::make('description')
                        ->label('Description')
                        ->rows(2)
                        ->helperText('Optional: details or examples of assets included in this group.'),

                    Forms\Components\TextInput::make('useful_life_months')
                        ->label('Default Useful Life (months)')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Number of months used as the default useful life for assets in this group.'),

                    Forms\Components\Select::make('default_method')
                        ->label('Default Depreciation Method')
                        ->native(false)
                        ->searchable()
                        ->options(DepreciationMethod::class)
                        ->helperText('Method to apply by default (can be overridden at the asset level).'),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Group Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('useful_life_months')
                    ->label('Default Useful Life (months)')
                    ->sortable(),

                Tables\Columns\TextColumn::make('default_method')
                    ->label('Default Method')
                    ->badge(),

                Tables\Columns\TextColumn::make('assets_count')
                    ->label('Assets')
                    ->counts('assets')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->since(),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make()
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
            'index' => Pages\ManageAssetGroups::route('/'),
        ];
    }
}
