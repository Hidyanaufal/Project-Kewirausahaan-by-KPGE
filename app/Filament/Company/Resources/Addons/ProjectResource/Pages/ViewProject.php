<?php

namespace App\Filament\Company\Resources\Addons\ProjectResource\Pages;

use App\Enums\Addons\ProjectStatus;
use App\Filament\Company\Resources\Addons\ProjectResource;
use Filament\Actions;
use Filament\Actions\ActionGroup;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\IconPosition;
use Illuminate\Contracts\Support\Htmlable;
use App\Filament\Company\Resources\Addons\ProjectResource\RelationManagers;
use App\Helpers\AuthHelper;
use App\Models\Addons\Project;
use Filament\Notifications\Notification;
use Rmsramos\Activitylog\Actions\ActivityLogTimelineSimpleAction;

class ViewProject extends ViewRecord
{
    use \App\Concerns\HideFromSpotlight;

    protected static string $resource = ProjectResource::class;

    protected function getAllRelationManagers(): array
    {
        $relations = [
            RelationManagers\AttachmentsRelationManager::class,
        ];

        if ($this->getRecord()->status == ProjectStatus::Approved) {
            $relations = array_merge($relations, [
                RelationManagers\EstimatesRelationManager::class,
                RelationManagers\InvoicesRelationManager::class,
                RelationManagers\BillsRelationManager::class,
            ]);
        }

        return $relations;
    }

    protected function isApprover(): bool
    {
        return $this->getRecord()->status == ProjectStatus::Draft && AuthHelper::isAdmin();
    }

    protected function getApproveAction(): Actions\Action
    {
        return Actions\Action::make('approve')
            ->color('info')
            ->requiresConfirmation()
            ->outlined()
            ->icon('heroicon-m-check-badge')
            ->visible(fn() => $this->isApprover())
            ->action(function () {
                $record = $this->getRecord();
                $record->number = Project::generateProjectNumber($record->project_type_id);
                $record->status = ProjectStatus::Approved;
                $record->save();
                Notification::make()
                    ->success()
                    ->title('Project Approved')
                    ->body("The project <strong>{$record->name}</strong> has been successfully approved with number <strong>{$record->number}</strong>.")
                    ->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                $this->getApproveAction(),
                ActivityLogTimelineSimpleAction::make('Activities')->color('info'),
                Actions\EditAction::make()
                    ->color('primary')
                    ->label('Edit Project')
                    ->outlined(),
                Actions\DeleteAction::make(),
            ])
                ->label('Actions')
                ->button()
                ->outlined()
                ->dropdownPlacement('bottom-end')
                ->icon('heroicon-m-chevron-down')
                ->iconPosition(IconPosition::After),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->number ?? 'Draft';
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('General Information')
                ->columns(2)
                ->schema([
                    TextEntry::make('number')
                        ->label('Project Number'),
                    TextEntry::make('name')
                        ->label('Project Name'),
                    TextEntry::make('type.name')
                        ->label('Type'),
                    TextEntry::make('client.name')
                        ->label('Client'),
                    TextEntry::make('pic.name')
                        ->label('PIC Internal'),
                    TextEntry::make('pic_client.first_name')
                        ->label('PIC Client'),
                ]),

            Section::make('Timeline & Value')
                ->columns(2)
                ->schema([
                    TextEntry::make('start_date')
                        ->label('Start Date')
                        ->date(),
                    TextEntry::make('end_date')
                        ->label('End Date')
                        ->date(),
                    TextEntry::make('project_value')
                        ->label('Project Value')
                        ->currency(),
                    TextEntry::make('status')
                        ->badge()
                        ->label('Status'),
                ]),

            Section::make('Additional Details')
                ->columns()
                ->schema([
                    TextEntry::make('work_basis')
                        ->label('Basis of Work'),
                    TextEntry::make('description')
                        ->label('Description')
                        ->markdown()
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
