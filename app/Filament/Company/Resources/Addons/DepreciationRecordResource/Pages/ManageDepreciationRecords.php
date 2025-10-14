<?php

namespace App\Filament\Company\Resources\Addons\DepreciationRecordResource\Pages;

use App\Filament\Company\Resources\Addons\DepreciationRecordResource;
use Filament\Resources\Pages\ManageRecords;

class ManageDepreciationRecords extends ManageRecords
{
    protected static string $resource = DepreciationRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
