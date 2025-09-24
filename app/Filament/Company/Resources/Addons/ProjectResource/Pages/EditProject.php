<?php

namespace App\Filament\Company\Resources\Addons\ProjectResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Concerns\HasMediumContentWidth;
use App\Filament\Company\Resources\Addons\ProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    use HasMediumContentWidth;
    use HandlePageRedirect;

    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
