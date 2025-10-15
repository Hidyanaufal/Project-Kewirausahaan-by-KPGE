<?php

namespace App\Models;

use App\Concerns\CompanyOwned;
use Illuminate\Database\Eloquent\Model;
use App\Enums\Accounting\AccountDefaultKey;
use App\Models\Accounting\Account;

class AccountDefault extends Model
{
    use CompanyOwned;

    protected $fillable = [
        'company_id',
        'account_id',
        'key'
    ];

    protected $casts = [
        'key' => AccountDefaultKey::class,
    ];

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
