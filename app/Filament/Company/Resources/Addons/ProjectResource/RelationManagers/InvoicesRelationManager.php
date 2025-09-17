<?php

namespace App\Filament\Company\Resources\Addons\ProjectResource\RelationManagers;

use App\Filament\Company\Resources\Sales\InvoiceResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static bool $isLazy = false;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return InvoiceResource::table($table)
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->url(InvoiceResource\Pages\CreateInvoice::getUrl(['project' => $this->getOwnerRecord()->getKey()])),
            ]);
    }
}
