<?php

namespace App\Models\Addons;

use App\Concerns\CompanyOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectType extends Model
{
    use CompanyOwned;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'company_id'
    ];
}
