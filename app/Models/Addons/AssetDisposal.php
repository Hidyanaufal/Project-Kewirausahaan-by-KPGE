<?php

namespace App\Models\Addons;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDisposal extends Model
{
    protected $fillable = [
        'asset_id',
        'disposal_date',
        'disposal_value',
        'gain_loss',
        'notes'
    ];

    protected $casts = [
        'disposal_date' => 'date'
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
