<?php

namespace App\Filament\Company\Resources\Common\OfferingResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Concerns\HasMediumContentWidth;
use App\Filament\Company\Resources\Common\OfferingResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOffering extends CreateRecord
{
    use HandlePageRedirect;
    use HasMediumContentWidth;

    protected static string $resource = OfferingResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data = OfferingResource::applyAttributeChoices($data);

        return parent::handleRecordCreation($data);
    }
}
