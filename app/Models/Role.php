<?php

namespace App\Models;

use App\Concerns\CompanyOwned;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use CompanyOwned;
}
