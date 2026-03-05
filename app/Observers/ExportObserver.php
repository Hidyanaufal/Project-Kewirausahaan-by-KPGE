<?php

namespace App\Observers;

use App\Models\Export;
use Filament\Facades\Filament;

class ExportObserver
{
    public function creating(Export $export): void
    {
        $tenant = Filament::getTenant();
        if ($tenant !== null && isset($tenant->id)) {
            $export->company_id = $tenant->id;
        }
    }
}
