<?php

namespace App\Filament\Company\Resources\Addons\ProjectTypeResource\Pages;

use App\Filament\Company\Resources\Addons\ProjectTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\MaxWidth;

class ManageProjectTypes extends ManageRecords
{
    protected static string $resource = ProjectTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->modalWidth(MaxWidth::Medium)
                ->slideOver(),
        ];
    }
}
