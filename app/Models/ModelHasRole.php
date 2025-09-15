<?php

namespace App\Models;

use App\Concerns\CompanyOwned;
use Illuminate\Database\Eloquent\Model;

class ModelHasRole extends Model
{
    use CompanyOwned;

    protected $table = 'model_has_roles';

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
