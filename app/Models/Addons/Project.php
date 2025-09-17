<?php

namespace App\Models\Addons;

use App\Concerns\CompanyOwned;
use App\Enums\Addons\ProjectStatus;
use App\Models\Accounting\Estimate;
use App\Models\Accounting\Invoice;
use App\Models\Common\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use CompanyOwned;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'project_type_id',
        'client_id',
        'pic_id',
        'number',
        'name',
        'description',
        'request_number_date',
        'project_value',
        'start_date',
        'end_date',
        'work_basis',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => ProjectStatus::class,
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class, 'project_type_id');
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
