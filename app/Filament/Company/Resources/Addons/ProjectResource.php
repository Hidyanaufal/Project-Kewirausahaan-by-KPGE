<?php

namespace App\Filament\Company\Resources\Addons;

use App\Concerns\HasOptions;
use App\Concerns\HasPermissionCheck;
use App\Enums\Addons\ProjectStatus;
use App\Filament\Company\Resources\Addons\ProjectResource\Pages;
use App\Models\Addons\Project;
use App\Models\Addons\ProjectType;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    use HasOptions;
    use HasPermissionCheck;

    protected static ?string $model = Project::class;

    public static function options(
        ?string $key = null,
        ?string $label = null,
        ?string $name = null,
        ?string $id = null
    ): Select {
        $key   = $key   ?? static::getOptionKey();
        $label = $label ?? static::getOptionLabel();
        $name  = $name  ?? static::getOptionName();
        $id    = $id    ?? static::getOptionId();

        $modalHeading = __('filament-forms::components.select.actions.create_option.modal.heading') . ' ' . $label;

        return Select::make($key)
            ->label($label)
            ->createOptionModalHeading($modalHeading)
            ->searchable()
            ->getSearchResultsUsing(function (string $search) use ($id) {
                return static::getModel()::query()
                    ->where('number', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn($item) => [
                        $item->$id => "{$item->number} - {$item->name}",
                    ]);
            })
            ->getOptionLabelUsing(
                fn($value): ?string =>
                optional(static::getModel()::find($value), fn($item) => "{$item->number} - {$item->name}")
            )
            ->preload()
            ->required();
    }


    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Project Information')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('number')
                        ->label('Project Number')
                        ->hiddenOn(['create'])
                        ->disabledOn(['edit'])
                        ->required(),

                    Forms\Components\DatePicker::make('request_number_date')
                        ->hiddenOn(['create'])
                        ->disabled()
                        ->required(),

                    Forms\Components\Select::make('project_type_id')
                        ->label('Type')
                        ->relationship('type', 'name')
                        ->required(),

                    Forms\Components\Select::make('client_id')
                        ->label('Client')
                        ->relationship('client', 'name')
                        ->required(),

                    Forms\Components\Select::make('pic_id')
                        ->label('PIC')
                        ->relationship('pic', 'name')
                        ->required(),

                    Forms\Components\TextInput::make('name')
                        ->label('Project Name')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Textarea::make('description')
                        ->label('Description')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Timeline & Value')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('project_value')
                        ->label('Project Value')
                        ->columnSpanFull()
                        ->money(),

                    Forms\Components\DatePicker::make('start_date')
                        ->label('Start Date')
                        ->native(false),

                    Forms\Components\DatePicker::make('end_date')
                        ->label('End Date')
                        ->native(false),
                ]),

            Forms\Components\Section::make('Additional')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('work_basis')
                        ->columnSpanFull()
                        ->label('Basis of Work (Work Order/Contract)')
                        ->maxLength(255),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->sortable()
                    ->searchable(isIndividual: false)
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('name')
                    ->label('Project Name')
                    ->searchable(isIndividual: false)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('type.name')
                    ->label('Type')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('pic.name')
                    ->label('PIC')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('request_number_date')
                    ->label('Request Date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('project_value')
                    ->label('Value')
                    ->money('idr')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('start_date')
                    ->label('Start Date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('end_date')
                    ->label('End Date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Project Status')
                    ->options(ProjectStatus::class)
                    ->searchable(),

                SelectFilter::make('project_type_id')
                    ->label('Project Type')
                    ->options(fn() => ProjectType::get()->pluck('name', 'id'))
                    ->searchable()
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ActionGroup::make([
                        Tables\Actions\EditAction::make(),
                        Tables\Actions\ViewAction::make(),
                    ])->dropdown(false),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'view' => Pages\ViewProject::route('/{record}'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
        ];
    }
}
