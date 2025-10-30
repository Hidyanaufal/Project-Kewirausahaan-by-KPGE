<?php

namespace App\Models\Addons;

use App\Concerns\CompanyOwned;
use App\Enums\Addons\DepreciationMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepreciationRecord extends Model
{
    use CompanyOwned;

    protected $fillable = [
        'asset_id',
        'fiscal_year',
        'opening_fiscal_book_value',
        'depreciation_expense',
        'closing_fiscal_book_value',
        'commercial_method',
        'fiscal_method',
        'monthly_breakdown',
        'company_id'
    ];

    protected $casts = [
        'monthly_breakdown' => 'array',
        'fiscal_year'       => 'integer',
        'fiscal_method'     => DepreciationMethod::class,
        'commercial_method' => DepreciationMethod::class,
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
