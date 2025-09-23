<?php

namespace App\Filament\Company\Resources\Common\OfferingResource\Pages;

use App\Concerns\HasMediumContentWidth;
use App\Filament\Company\Resources\Common\OfferingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;

class ListOfferings extends ListRecords
{
    use HasMediumContentWidth;

    protected static string $resource = OfferingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
