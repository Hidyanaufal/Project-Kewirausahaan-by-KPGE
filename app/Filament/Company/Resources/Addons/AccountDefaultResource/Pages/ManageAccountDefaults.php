<?php

namespace App\Filament\Company\Resources\Addons\AccountDefaultResource\Pages;

use App\Filament\Company\Resources\Addons\AccountDefaultResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\MaxWidth;

class ManageAccountDefaults extends ManageRecords
{
    protected static string $resource = AccountDefaultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->slideOver()
                ->modalWidth(MaxWidth::Medium),
        ];
    }
}
