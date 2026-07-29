<?php

namespace App\Filament\Company\Resources\Addons\ProjectResource\Pages;

use App\Actions\Addons\ExcelImportAction;
use App\Filament\Company\Resources\Addons\ProjectResource;
use App\Helpers\AuthHelper;
use App\Imports\LegacyProjectInvoiceWorkbookImport;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            ExcelImportAction::make(
                importClass: LegacyProjectInvoiceWorkbookImport::class,
                actionName: 'importLegacyProjectAndInvoice',
                label: 'Import Legacy Project & Invoice',
                icon: 'heroicon-o-document-arrow-up',
                successMessage: 'Legacy workbook imported successfully.',
                context: [
                    'companyId' => AuthHelper::currentCompany(),
                    'actorId' => AuthHelper::currentUser(),
                    'forceReimport' => true,
                ],
            ),
        ];
    }
}
