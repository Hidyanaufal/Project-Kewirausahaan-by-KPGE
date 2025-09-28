<?php

namespace App\Filament\Company\Clusters\Settings\Resources\DocumentDefaultResource\Pages;

use App\Enums\Setting\Template;
use App\Filament\Company\Clusters\Settings\Resources\DocumentDefaultResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use PHPUnit\Event\Code\Throwable;

class EditDocumentDefault extends EditRecord
{
    protected static string $resource = DocumentDefaultResource::class;

    public function getRecordTitle(): string | Htmlable
    {
        return $this->record->type->getLabel();
    }

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->authorizeAccess();

        try {
            $this->record->template;
            if (!$this->record->template) {
                $this->record->template = Template::Default;
                $this->record->save();
            }
        } catch (\Throwable $e) {
            $this->record->template = Template::Default;
            $this->record->save();
        }

        $this->fillForm();

        $this->previousUrl = url()->previous();
    }

    public function getBreadcrumbs(): array
    {
        $breadcrumbs = parent::getBreadcrumbs();

        array_pop($breadcrumbs);

        return $breadcrumbs;
    }
}
