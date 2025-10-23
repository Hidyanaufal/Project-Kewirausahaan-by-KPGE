<?php

namespace App\Filament\Forms\Addons;

use App\Filament\Company\Resources\Addons\ProjectTypeResource;
use App\Helpers\AuthHelper;
use App\Models\Company;
use Filament\Forms;

class FilterProjectCompany
{
    public static function make(): array
    {
        return [
            Forms\Components\Select::make('company_id')
                ->label('Company')
                ->visible(fn() => AuthHelper::isPrimaryCompany())
                ->searchable()
                ->options(function () {
                    $companies = Company::query()
                        ->withoutGlobalScopes()
                        ->pluck('name', 'id')
                        ->toArray();
                    return $companies;
                }),
            ProjectTypeResource::options()
                ->required(false),
        ];
    }
}
