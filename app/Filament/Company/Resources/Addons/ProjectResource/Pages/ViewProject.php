<?php

namespace App\Filament\Company\Resources\Addons\ProjectResource\Pages;

use App\Concerns\HasMediumContentWidth;
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

class ViewProject extends ViewRecord
{
    use HasMediumContentWidth;

    protected static string $resource = ProjectResource::class;

    protected function getAllRelationManagers(): array
    {
        return [
            RelationManagers\EstimatesRelationManager::class,
            RelationManagers\InvoicesRelationManager::class,
            RelationManagers\BillsRelationManager::class,
            RelationManagers\AttachmentsRelationManager::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
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
        return $this->getRecord()->number;
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
                        ->label('PIC'),
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
                        ->money('idr', true),
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
