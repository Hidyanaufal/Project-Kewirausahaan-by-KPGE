<?php

namespace App\Filament\Company\Resources\Addons;

use App\Concerns\HasPermissionCheck;
use BezhanSalleh\FilamentShield\Resources\RoleResource as ResourcesRoleResource;

class RoleResource extends ResourcesRoleResource
{
    use HasPermissionCheck;
}
