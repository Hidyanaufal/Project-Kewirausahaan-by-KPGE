<?php

namespace App\Filament\Company\Resources\Addons;

use App\Concerns\HasOptions;
use App\Concerns\HasPermissionCheck;
use App\Enums\Addons\ProjectStatus;
use App\Enums\Accounting\BillStatus;
use App\Enums\Accounting\InvoiceStatus;
use App\Filament\Company\Resources\Addons\ProjectResource\Pages;
use App\Filament\Exports\Addons\ProjectExporter;
use App\Models\Addons\Project;
use App\Models\Addons\ProjectType;
use App\Models\Common\Client;
use App\Models\Common\Contact;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Rmsramos\Activitylog\Actions\ActivityLogTimelineTableAction;

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
            ->options(function () use ($id) {
                return Project::orderBy('id', 'desc')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn($item) => [
                        $item->$id => "{$item->number} - {$item->name}",
                    ]);
            })
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
                        ->disabledOn(['edit']),

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
                        ->reactive()
                        ->afterStateUpdated(fn(callable $set) => $set('pic_client_id', null))
                        ->required(),

                    Forms\Components\Select::make('pic_id')
                        ->label('PIC Internal')
                        ->relationship('pic', 'name')
                        ->required(),

                    Forms\Components\TextInput::make('pic_client_name')
                        ->label('PIC Client')
                        ->maxLength(255)
                        ->nullable(),

                    Forms\Components\TextInput::make('name')
                        ->label('Project Name')
                        ->required()
                        ->columnSpanFull()
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
                        ->native(true),

                    Forms\Components\DatePicker::make('end_date')
                        ->label('End Date')
                        ->native(true),
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
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(function (Builder $query) {
                // Subquery Total Invoice — exclude cancelled
                $invoiceSubquery = \App\Models\Accounting\Invoice::query()
                    ->selectRaw('COALESCE(SUM(subtotal + profit_total - COALESCE(discount_total, 0)), 0)')
                    ->whereColumn('invoices.project_id', 'projects.id')
                    ->whereNotIn('status', ['cancelled']);

                // Subquery Total Biaya (Bill) — exclude cancelled
                $billSubquery = \App\Models\Accounting\Bill::query()
                    ->selectRaw('COALESCE(SUM(total), 0)')
                    ->whereColumn('bills.project_id', 'projects.id')
                    ->whereNotIn('status', ['cancelled']);

                $query
                    ->addSelect([
                        'approved_invoice_total_without_ppn_sum' => $invoiceSubquery,
                        'approved_bill_total_sum' => $billSubquery,
                    ]);
            })
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->sortable()
                    ->searchable(isIndividual: false)
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('name')
                    ->label('Project Name')
                    ->searchable(isIndividual: false)
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
                    ->badge(),

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

                Tables\Columns\TextColumn::make('approved_invoice_total_without_ppn_sum')
                    ->label('Total Invoice (Tanpa PPN)')
                    ->money('idr')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('approved_bill_total_sum')
                    ->label('Total Biaya')
                    ->money('idr')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('net_earning')
                    ->label('Net Earning')
                    ->money('idr')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(
                            '(COALESCE(approved_invoice_total_without_ppn_sum, 0) - COALESCE(approved_bill_total_sum, 0)) ' . $direction
                        );
                    })
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
            ->headerActions([
                Tables\Actions\ExportAction::make()
                    ->label('Export Excel')
                    ->exporter(ProjectExporter::class),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\ViewAction::make()->color('warning'),
                    ActivityLogTimelineTableAction::make('Activities')->color('info'),
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