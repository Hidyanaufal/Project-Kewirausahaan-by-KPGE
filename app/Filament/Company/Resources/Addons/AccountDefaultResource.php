<?php

namespace App\Filament\Company\Resources\Addons;

use App\Concerns\HasPermissionCheck;
use App\Enums\Accounting\AccountDefaultKey;
use App\Filament\Company\Resources\Addons\AccountDefaultResource\Pages;
use App\Models\AccountDefault;
use App\Models\Accounting\Transaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;

class AccountDefaultResource extends Resource
{
    use HasPermissionCheck;

    protected static ?string $model = AccountDefault::class;

    public static function form(Form $form): Form
    {
        return $form
            ->columns(1)
            ->schema([
                Forms\Components\Select::make('key')
                    ->required()
                    ->options(AccountDefaultKey::class)
                    ->native(false),
                Forms\Components\Select::make('account_id')
                    ->label('Account')
                    ->searchable()
                    ->options(Transaction::getChartAccountOptions())
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->searchable(),
                Tables\Columns\TextColumn::make('account.name'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth(MaxWidth::Medium),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAccountDefaults::route('/'),
        ];
    }
}
