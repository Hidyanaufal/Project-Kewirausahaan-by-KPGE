<?php

namespace App\Filament\Company\Resources\Addons;

use App\Concerns\HasPermissionCheck;
use App\Filament\Company\Resources\Addons\UserResource\Pages;
use App\Helpers\AuthHelper;
use App\Models\ModelHasRole;
use App\Models\Role;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    use HasPermissionCheck;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function form(Form $form): Form
    {
        return $form
            ->columns(1)
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: 'users',
                        column: 'email',
                        modifyRuleUsing: fn($rule, $record) => $rule->ignore($record?->id)
                    ),
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->dehydrateStateUsing(
                        fn($state) => ! empty($state) ? Hash::make($state) : null
                    )
                    ->hiddenOn(['edit'])
                    ->dehydrated(fn($state) => ! empty($state))
                    ->required(fn(string $operation): bool => $operation === 'create')
                    ->maxLength(255),
                Forms\Components\Select::make('roles')
                    ->required()
                    ->multiple()
                    ->preload()
                    ->options(fn() => Role::where('company_id', AuthHelper::currentCompany())->get()->pluck('name', 'id'))
                    ->searchable(),
                Forms\Components\CheckboxList::make('companies')
                    ->label('Company Access')
                    ->options(
                        \App\Models\Company::query()
                            ->pluck('name', 'id')
                    )
                    ->columns(1)
                    ->relationship(
                        name: 'companies',
                        titleAttribute: 'name'
                    )
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('positions.role.name')
                    ->label('Roles')
                    ->badge()
                    ->separator(',')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->modalWidth(MaxWidth::Medium)
                        ->mutateRecordDataUsing(function ($record) {
                            $record = $record->toArray();
                            $record['roles'] = ModelHasRole::where('model_id', $record['id'])->pluck('role_id');
                            return $record;
                        })
                        ->action(function (array $data, $record) {
                            DB::beginTransaction();
                            try {
                                relation_update($record, $data);
                                DB::table('model_has_roles')
                                    ->where([
                                        'model_type' => User::class,
                                        'model_id' => $record->id,
                                    ])
                                    ->delete();
                                if (isset($data['roles'])) {
                                    foreach ($data['roles'] as $role) {
                                        DB::table('model_has_roles')->insert([
                                            'model_type' => User::class,
                                            'model_id' => $record->id,
                                            'role_id' => $role,
                                            'company_id' => AuthHelper::currentCompany()
                                        ]);
                                    }
                                }
                                DB::commit();
                                Notification::make()
                                    ->success()
                                    ->title('Data updated successfully')
                                    ->body('The record has been saved.')
                                    ->send();
                            } catch (\Exception $e) {
                                DB::rollBack();
                                Notification::make()
                                    ->danger()
                                    ->title('An error occurred')
                                    ->body($e->getMessage())
                                    ->send();
                            }
                        })
                        ->slideOver(),
                    Tables\Actions\Action::make('reset_password')
                        ->icon('heroicon-o-lock-closed')
                        ->color('warning')
                        ->modalWidth(MaxWidth::Medium)
                        ->slideOver()
                        ->form([
                            Forms\Components\TextInput::make('password')
                                ->password()
                                ->required()
                                ->label('New Password')
                                ->same('password_confirmation') // validate against confirmation
                                ->dehydrateStateUsing(
                                    fn($state) => ! empty($state) ? Hash::make($state) : null
                                )
                                ->dehydrated(fn($state) => ! empty($state)),

                            Forms\Components\TextInput::make('password_confirmation')
                                ->password()
                                ->required()
                                ->label('Confirm Password')
                                ->dehydrated(false), // don't save this field
                        ])
                        ->action(function (array $data, $record) {
                            $record->password = $data['password'];
                            $record->save();
                            Notification::make()
                                ->success()
                                ->title('Password Reset Successful')
                                ->body("The password for user <strong>{$record->name}</strong> has been successfully reset.")
                                ->send();
                        }),
                    Tables\Actions\DeleteAction::make()
                ])
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageUsers::route('/'),
        ];
    }
}
