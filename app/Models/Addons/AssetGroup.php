<?php

namespace App\Models\Addons;

use App\Concerns\CompanyOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetGroup extends Model
{
    use CompanyOwned;

    protected $fillable = [
        'name',
        'description',
        'useful_life_months',
        'default_method',
        'company_id'
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'group_id');
    }
}
