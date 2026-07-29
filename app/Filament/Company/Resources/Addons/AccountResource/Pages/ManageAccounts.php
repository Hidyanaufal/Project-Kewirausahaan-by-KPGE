<?php

namespace App\Filament\Company\Resources\Addons\AccountResource\Pages;

use App\Actions\Addons\ExcelImportAction;
use App\Actions\Addons\ImportOpeningBalanceWithPreviewAction;
use App\Filament\Company\Resources\Addons\AccountResource;
use App\Helpers\AuthHelper;
use App\Imports\ChartOfAccountImport;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\MaxWidth;

class ManageAccounts extends ManageRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->slideOver()
                ->modalWidth(MaxWidth::Medium),
            ExcelImportAction::make(
                importClass: ChartOfAccountImport::class,
                actionName: 'importChartOfAccounts',
                templatePath: url('templates/coa_import_template.xlsx'),
                context: [
                    'companyId' => AuthHelper::currentCompany(),
                    'reset' => true,
                ]
            ),
            ImportOpeningBalanceWithPreviewAction::make(),
        ];
    }
}
