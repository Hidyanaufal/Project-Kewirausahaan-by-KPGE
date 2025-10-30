<?php

namespace App\Models\Addons;

use App\Concerns\CompanyOwned;
use App\Enums\Addons\DepreciationMethod;
use App\Models\Accounting\Account;
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
        'company_id',
        'asset_account_id',
        'contra_account_id',
    ];

    protected $casts = [
        'default_method' => DepreciationMethod::class,
    ];

    public function assetAccount()
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function contraAsset()
    {
        return $this->belongsTo(Account::class, 'contra_account_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'group_id');
    }
}
