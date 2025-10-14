<?php

namespace App\Filament\Company\Resources\Addons\AssetResource\Pages;

use App\Filament\Company\Resources\Addons\AssetResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageAssets extends ManageRecords
{
    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->slideOver()
                ->modalWidth(\Filament\Support\Enums\MaxWidth::ThreeExtraLarge),
        ];
    }
}
