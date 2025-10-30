<?php

namespace App\Filament\Company\Resources\Addons;

use App\Concerns\HasPermissionCheck;
use App\Filament\Company\Resources\Addons\AccountResource\Pages;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Enums\Accounting\AccountCategory;
use App\Enums\Banking\BankAccountType;
use App\Filament\Forms\Components\CreateCurrencySelect;
use App\Utilities\Accounting\AccountCode;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Support\Enums\MaxWidth;

class AccountResource extends Resource
{
    use HasPermissionCheck;

    protected static ?string $model = Account::class;

    protected static ?string $modelLabel = 'Chart of Accounts';

    public static function form(Form $form): Form
    {
        return $form
            ->columns(1)
            ->schema([
                // Subtype selection (Type)
                Forms\Components\Select::make('subtype_id')
                    ->label('Type')
                    ->required()
                    ->searchable()
                    ->options(
                        AccountSubtype::all()
                            ->groupBy(fn($s) => $s->type->getLabel())
                            ->map(fn($group) => $group->mapWithKeys(fn($s) => [$s->id => $s->name]))
                            ->toArray()
                    )
                    ->live()
                    ->afterStateUpdated(static function (?string $state, Set $set): void {
                        if ($state) {
                            $subtype = AccountSubtype::find($state);
                            if ($subtype) {
                                $set('code', AccountCode::generate($subtype));
                                $set('is_bank_account', false);
                                $set('bankAccount.type', null);
                                $set('bankAccount.number', null);
                            }
                        }
                    }),

                // Account code
                Forms\Components\TextInput::make('code')
                    ->label('Code')
                    ->required()
                    ->unique(table: Account::class, column: 'code', ignoreRecord: true)
                    ->maxLength(50)
                    ->validationAttribute('account code'),

                // Account name
                Forms\Components\TextInput::make('name')
                    ->label('Name')
                    ->required(),

                // Checkbox: is bank account
                Forms\Components\Checkbox::make('is_bank_account')
                    ->live()
                    ->visible(function (Get $get) {
                        $subtypeId = $get('subtype_id');
                        $subtype = $subtypeId ? AccountSubtype::find($subtypeId) : null;
                        return $subtype && in_array($subtype->category, [
                            AccountCategory::Asset,
                            AccountCategory::Liability,
                        ]) && $subtype->multi_currency;
                    })
                    ->afterStateUpdated(static function ($state, Get $get, Set $set) {
                        if ($state) {
                            $subtypeId = $get('subtype_id');
                            $subtype = $subtypeId ? AccountSubtype::find($subtypeId) : null;
                            if ($subtype) {
                                $set(
                                    'bankAccount.type',
                                    $subtype->category === AccountCategory::Asset
                                        ? BankAccountType::Depository->value
                                        : BankAccountType::Credit->value
                                );
                            }
                        } else {
                            $set('bankAccount.type', null);
                            $set('bankAccount.number', null);
                        }
                    }),

                // Group bank account (relationship)
                Forms\Components\Group::make()
                    ->relationship('bankAccount')
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->label('Bank account type')
                            ->options([
                                BankAccountType::Depository->value => 'Depository',
                                BankAccountType::Investment->value => 'Investment',
                                BankAccountType::Credit->value => 'Credit',
                                BankAccountType::Loan->value => 'Loan',
                            ])
                            ->searchable()
                            ->required(),

                        Forms\Components\TextInput::make('number')
                            ->label('Bank account number')
                            ->unique(ignoreRecord: true, modifyRuleUsing: function (Unique $rule, $state) {
                                $companyId = Auth::user()->currentCompany->id;
                                return $rule->where('company_id', $companyId)->where('number', $state);
                            })
                            ->maxLength(20)
                            ->validationAttribute('account number'),
                    ])
                    ->visible(fn(Get $get) => (bool) $get('is_bank_account')),

                // Currency
                CreateCurrencySelect::make('currency_code')
                    ->disabledOn('edit')
                    ->required(false)
                    ->requiredIfAccepted('is_bank_account')
                    ->validationMessages([
                        'required_if_accepted' => 'Currency is required for bank accounts.',
                    ])
                    ->visible(function (Get $get): bool {
                        $subtypeId = $get('subtype_id');
                        $subtype = $subtypeId ? AccountSubtype::find($subtypeId) : null;
                        return $subtype?->multi_currency ?? false;
                    }),

                // Description
                Forms\Components\Textarea::make('description')
                    ->label('Description'),

                // Archived
                Forms\Components\Checkbox::make('archived')
                    ->label('Archive account')
                    ->helperText('Archived accounts cannot be used in new transactions.')
                    ->hiddenOn('create'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Account Code')
                    ->description(fn($record) => $record->description)
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Account Name')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('parent.code')
                    ->label('Parent Code')
                    ->description(fn($record) => $record->parent?->description)
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('parent.name')
                    ->label('Parent Name')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Category')
                    ->options(
                        collect(\App\Enums\Accounting\AccountCategory::cases())
                            ->mapWithKeys(fn($c) => [$c->value => $c->name])
                            ->toArray()
                    ),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->options(
                        collect(\App\Enums\Accounting\AccountType::cases())
                            ->mapWithKeys(fn($t) => [$t->value => $t->name])
                            ->toArray()
                    ),

                Tables\Filters\SelectFilter::make('parent_id')
                    ->label('Parent Account')
                    ->options(Account::pluck('name', 'id'))
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->slideOver()
                        ->modalWidth(MaxWidth::Medium),
                    Tables\Actions\DeleteAction::make(),
                ])
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('updateParent')
                        ->label('Update Parent Account')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->form([
                            Forms\Components\Select::make('parent_id')
                                ->label('New Parent Account')
                                ->options(Account::pluck('name', 'id'))
                                ->searchable()
                                ->required(),
                        ])
                        ->slideOver()
                        ->modalWidth(MaxWidth::Medium)
                        ->action(function (array $data, $records) {
                            foreach ($records as $record) {
                                if ($record->id !== (int)$data['parent_id']) {
                                    $record->update(['parent_id' => $data['parent_id']]);
                                }
                            }

                            Notification::make()
                                ->title('Parent Account Updated')
                                ->body('Selected accounts have been successfully updated.')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->color('primary'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAccounts::route('/'),
        ];
    }
}
