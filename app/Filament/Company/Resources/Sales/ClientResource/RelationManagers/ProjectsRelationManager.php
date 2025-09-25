<?php

namespace App\Filament\Company\Resources\Sales\ClientResource\RelationManagers;

use App\Filament\Company\Resources\Addons\ProjectResource;
use App\Filament\Company\Resources\Addons\ProjectResource\Pages\ViewProject;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    protected static bool $isLazy = false;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return ProjectResource::table($table)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->url(fn(Model $record) => ViewProject::getUrl(['record' => $record]))
                        ->color('warning'),
                ])
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->url(ProjectResource\Pages\CreateProject::getUrl(['client' => $this->getOwnerRecord()->getKey()])),
            ]);
    }
}
