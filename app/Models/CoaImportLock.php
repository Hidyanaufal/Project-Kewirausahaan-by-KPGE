<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoaImportLock extends Model
{
    protected $fillable = [
        'company_id',
        'action',
        'allowed',
        'performed_by',
        'performed_at',
        'note',
    ];
}
