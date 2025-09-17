<?php

namespace App\Filament\Company\Resources\Sales\ClientResource\RelationManagers;

use App\Filament\Company\Resources\Addons\ProjectResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

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
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->url(ProjectResource\Pages\CreateProject::getUrl(['client' => $this->getOwnerRecord()->getKey()])),
            ]);
    }
}
