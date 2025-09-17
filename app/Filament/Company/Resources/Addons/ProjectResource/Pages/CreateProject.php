<?php

namespace App\Filament\Company\Resources\Addons\ProjectResource\Pages;

use App\Concerns\HasMediumContentWidth;
use App\Enums\Addons\ProjectStatus;
use App\Filament\Company\Resources\Addons\ProjectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProject extends CreateRecord
{
    use HasMediumContentWidth;

    protected static string $resource = ProjectResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['request_number_date'] = today();
        $data['status'] = ProjectStatus::Approved;

        $prefix = \App\Models\Addons\ProjectType::query()
            ->whereKey($data['project_type_id'])
            ->value('code') ?? 'DOC';

        $year = now()->format('y');
        $basePrefix = $prefix . $year;

        $lastDocument = \App\Models\Addons\Project::query()
            ->where('number', 'like', "{$basePrefix}-%")
            ->orderBy('number', 'desc')
            ->first();

        $lastCounter = $lastDocument
            ? (int) substr($lastDocument->number, -3)
            : 0;

        $newCounter = $lastCounter + 1;

        $data['number'] = $basePrefix . '-' . str_pad($newCounter, 3, '0', STR_PAD_LEFT);

        return $data;
    }
}
