<?php

namespace App\Filament\Company\Resources\Addons;

use App\Concerns\HasOptions;
use App\Filament\Company\Resources\Addons\MemberResource\Pages;
use App\Models\Addons\Member;
use Filament\Forms\Form;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MemberResource extends Resource
{
    use HasOptions;

    protected static ?string $model = Member::class;

    public static function canCreateOption(): bool
    {
        return true;
    }

    public static function getFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('name')->required()
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::getFormSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ManageMembers::route('/'),
        ];
    }
}
