<?php

namespace App\Filament\Company\Resources\Addons\AccountResource\Pages;

use App\Actions\Addons\ExcelImportAction;
use App\Filament\Company\Resources\Addons\AccountResource;
use App\Helpers\AuthHelper;
use App\Imports\ChartOfAccountImport;
use App\Imports\OpeningBalanceImport;
use Filament\Actions;
use Filament\Forms\Components\Select;
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
                    'reset' => true
                ]
            ),
            ExcelImportAction::make(
                importClass: OpeningBalanceImport::class,
                actionName: 'importOpeningBalance',
                label: 'Import Saldo Awal',
                icon: 'heroicon-o-currency-dollar',
                templatePath: url('templates/saldo-awal-kpg'),
                context: [
                    'companyId' => AuthHelper::currentCompany(),
                ],
                extraFormSchema: [
                    Select::make('year')
                        ->label('Tahun (Fiscal Year)')
                        ->required()
                        ->options(function () {
                            $currentYear = company_today()->year;
                            $years = range($currentYear, max($currentYear - 15, 2000));

                            return collect($years)
                                ->mapWithKeys(fn (int $y) => [$y => "FY {$y}"])
                                ->toArray();
                        })
                        ->searchable(),
                ],
            )
        ];
    }
}
