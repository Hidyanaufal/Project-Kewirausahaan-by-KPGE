<?php

namespace App\Filament\Company\Resources\Addons;

use App\Actions\Addons\GenerateYearlyForAsset;
use App\Actions\Addons\PostInitialAssetRecognition;
use App\Concerns\HasPermissionCheck;
use App\Enums\Addons\AssetStatus;
use App\Enums\Addons\DepreciationMethod;
use App\Filament\Company\Resources\Addons\AssetResource\Pages;
use App\Models\Addons\Asset;
use App\Models\Addons\AssetGroup;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Notifications\Notification;

class AssetResource extends Resource
{
    use HasPermissionCheck;

    protected static ?string $model = Asset::class;

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Select::make('group_id')
                ->label('Asset Group')
                ->options(fn() => AssetGroup::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->native(false)
                ->required(),

            Forms\Components\TextInput::make('asset_code')
                ->label('Asset Code')
                ->maxLength(100),

            Forms\Components\TextInput::make('name')
                ->label('Asset Name')
                ->required(),

            Forms\Components\DatePicker::make('acquisition_date')
                ->label('Acquisition Date')
                ->required(),

            Forms\Components\TextInput::make('acquisition_cost')
                ->label('Acquisition Cost')
                ->money('IDR', true)
                ->required()
                ->helperText('The total cost to acquire the asset, including taxes or fees.'),

            Forms\Components\TextInput::make('salvage_value')
                ->label('Salvage Value')
                ->money('IDR', true)
                ->default(0)
                ->helperText('Estimated residual value after the asset’s useful life ends. This reduces total depreciation.'),

            Forms\Components\TextInput::make('useful_life_months')
                ->label('Useful Life (months)')
                ->numeric()
                ->helperText('Leave empty to use group default.'),

            Forms\Components\Select::make('depreciation_method')
                ->label('Depreciation Method')
                ->options(DepreciationMethod::class)
                ->native(false)
                ->helperText('Leave empty to use the group default.'),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Asset Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('group.name')
                    ->label('Asset Group')
                    ->sortable(),

                Tables\Columns\TextColumn::make('acquisition_date')
                    ->label('Acquisition Date')
                    ->date(),

                Tables\Columns\TextColumn::make('acquisition_cost')
                    ->label('Acquisition Cost')
                    ->currency(),

                Tables\Columns\TextColumn::make('salvage_value')
                    ->label('Salvage Value')
                    ->currency(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('group_id')
                    ->label('Asset Group')
                    ->options(fn() => AssetGroup::pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(AssetStatus::cases())->mapWithKeys(fn($s) => [$s->value => method_exists($s, 'label') ? $s->label() : ucfirst($s->value)])->toArray()),
            ])
            ->actions([
                // Tables\Actions\Action::make('postInitialRecognition')
                //     ->label('Post initial recognition')
                //     ->icon('heroicon-o-document-plus')
                //     ->color('gray')
                //     ->requiresConfirmation()
                //     ->visible(fn (Asset $record): bool => (bool) ($record->group?->assetAccount))
                //     ->action(function (Asset $record): void {
                //         $result = PostInitialAssetRecognition::run(asset: $record);

                //         if ($result['posted']) {
                //             Notification::make()
                //                 ->success()
                //                 ->title($result['message'])
                //                 ->send();

                //             return;
                //         }

                //         Notification::make()
                //             ->warning()
                //             ->title('Not posted')
                //             ->body($result['message'])
                //             ->send();
                //     }),

                Tables\Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth(\Filament\Support\Enums\MaxWidth::ThreeExtraLarge),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('generateYear')
                        ->label('Generate Depreciation')
                        ->icon('heroicon-s-calculator')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $created = 0;
                            $skipped = 0;

                            foreach ($records as $asset) {
                                $result = GenerateYearlyForAsset::run(asset: $asset);
                                $created += count($result['created'] ?? []);
                                $skipped += count($result['skipped'] ?? []);
                            }

                            Notification::make()
                                ->success()
                                ->title('Depreciation Generated')
                                ->body("Successfully processed depreciation for the selected assets.  
    **{$created}** record(s) created, **{$skipped}** skipped (already exist or fully depreciated).")
                                ->send();
                        }),

                    Tables\Actions\DeleteBulkAction::make(),
                ])
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAssets::route('/'),
        ];
    }
}
