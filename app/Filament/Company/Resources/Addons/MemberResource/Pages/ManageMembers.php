<?php

namespace App\Filament\Company\Resources\Addons\MemberResource\Pages;

use App\Filament\Company\Resources\Addons\MemberResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageMembers extends ManageRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
