<?php

namespace App\Filament\Company\Clusters;

use Filament\Clusters\Cluster;

class Settings extends Cluster
{
    use \App\Concerns\HasPermissionCheck;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
}
