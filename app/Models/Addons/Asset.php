<?php

namespace App\Models\Addons;

use App\Concerns\CompanyOwned;
use App\Enums\Accounting\JournalEntryType;
use App\Enums\Addons\AssetStatus;
use App\Enums\Addons\DepreciationMethod;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

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

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Asset $record) {
            $asset = $record;
            $value = $asset->acquisition_cost;

            if ($asset->group && $asset->group->assetAccount) {
                Log::debug('debug from: ' . __CLASS__, [
                    'group'         => $asset->group,
                    'assetAccount'  => $asset->group->assetAccount,
                ]);

                // Buat transaksi jurnal untuk pencatatan awal aset
                $transaction = Transaction::create([
                    'bank_account_id' => null,
                    'type'            => 'journal',
                    'description'     => "Initial asset recognition for {$asset->name}",
                    'posted_at'       => now(),
                    'amount'          => $value,
                    'reviewed'        => false
                ]);

                // Debit akun aset (menambah nilai aset)
                JournalEntry::create([
                    'transaction_id' => $transaction->id,
                    'account_id'     => $asset->group->assetAccount->id,
                    'type'           => JournalEntryType::Debit,
                    'amount'         => $value,
                    'description'    => "Asset acquisition - {$asset->name}",
                ]);
            }
        });
    }


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
