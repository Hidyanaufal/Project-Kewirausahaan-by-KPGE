<?php

namespace App\Filament\Company\Resources\Addons\ProjectResource\Pages;

use App\Filament\Company\Resources\Addons\ProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
