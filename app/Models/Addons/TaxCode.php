<?php

namespace App\Models\Addons;

use Illuminate\Database\Eloquent\Model;

class TaxCode extends Model
{
    protected $fillable = [
        'code',
        'description'
    ];
}
