<?php

namespace App\Models\Addons;

use App\Concerns\CompanyOwned;
use App\Enums\Addons\AssetStatus;
use App\Enums\Addons\DepreciationMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    use CompanyOwned;

    protected $fillable = [
        'asset_code',
        'name',
        'group_id',
        'acquisition_date',
        'acquisition_cost',
        'useful_life_months',
        'salvage_value',
        'depreciation_method',
        'status',
        'meta',
        'company_id'
    ];

    protected $casts = [
        'acquisition_date' => 'date',
        'meta' => 'array',
        'depreciation_method' => DepreciationMethod::class,
        'status' => AssetStatus::class
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(AssetGroup::class, 'group_id');
    }

    public function depreciationRecords(): HasMany
    {
        return $this->hasMany(DepreciationRecord::class);
    }

    public function disposals(): HasMany
    {
        return $this->hasMany(AssetDisposal::class);
    }

    public function remainingLifeMonths(): int
    {
        $life = $this->useful_life_months ?? ($this->group?->useful_life_months ?? 0);
        if ($life <= 0) return 0;
        $monthsUsed = $this->acquisition_date->diffInMonths(now());
        return max(0, $life - $monthsUsed);
    }
}
