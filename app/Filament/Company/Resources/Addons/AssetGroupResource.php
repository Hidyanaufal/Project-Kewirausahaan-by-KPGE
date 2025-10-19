<?php

namespace App\Filament\Company\Resources\Addons;

use App\Concerns\HasPermissionCheck;
use App\Enums\Addons\DepreciationMethod;
use App\Filament\Company\Resources\Addons\AssetGroupResource\Pages;
use App\Filament\Forms\Components\CreateAccountSelect;
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
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Group Name')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->placeholder('Office Equipment')
                    ->validationMessages([
                        'required' => 'Please enter the group name.',
                        'max' => 'The group name may not exceed 255 characters.',
                    ]),

                Forms\Components\Textarea::make('description')
                    ->label('Description')
                    ->columnSpanFull()
                    ->rows(2),

                Forms\Components\TextInput::make('useful_life_months')
                    ->label('Default Useful Life (months)')
                    ->numeric()
                    ->minValue(1)
                    ->helperText('Number of months used as the default useful life for assets in this group.')
                    ->validationMessages([
                        'numeric' => 'The useful life must be a number.',
                        'min' => 'The useful life must be at least 1 month.',
                    ]),

                Forms\Components\Select::make('default_method')
                    ->label('Default Depreciation Method')
                    ->native(false)
                    ->searchable()
                    ->options(DepreciationMethod::class)
                    ->helperText('Method to apply by default (can be overridden at the asset level).'),

                CreateAccountSelect::make('asset_account_id')
                    ->label('Asset Account')
                    ->required()
                    ->validationMessages([
                        'required' => 'The asset account is required for this asset group.',
                    ]),

                CreateAccountSelect::make('contra_account_id')
                    ->label('Contra Asset Account')
                    ->required()
                    ->validationMessages([
                        'required' => 'The contra asset account is required for this asset group.',
                    ]),
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

                Tables\Columns\TextColumn::make('assetAccount.name')
                    ->label('Asset Account')
                    ->description(fn(AssetGroup $model) => $model->assetAccount->code),

                Tables\Columns\TextColumn::make('contraAsset.name')
                    ->label('Contra Asset Account')
                    ->description(fn(AssetGroup $model) => $model->contraAsset->code),

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
