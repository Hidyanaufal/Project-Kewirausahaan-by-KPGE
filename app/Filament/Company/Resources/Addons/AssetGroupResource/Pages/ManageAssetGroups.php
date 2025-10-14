<?php

namespace App\Filament\Company\Resources\Addons\AssetGroupResource\Pages;

use App\Filament\Company\Resources\Addons\AssetGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageAssetGroups extends ManageRecords
{
    protected static string $resource = AssetGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->slideOver()
                ->modalWidth(\Filament\Support\Enums\MaxWidth::ThreeExtraLarge),
        ];
    }
}
