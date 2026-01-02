<?php

namespace App\Models\Addons;

use App\Concerns\CompanyOwned;
use App\Concerns\HasAttacments;
use App\Enums\Addons\ProjectStatus;
use App\Models\Accounting\Bill;
use App\Models\Accounting\Estimate;
use App\Models\Accounting\Invoice;
use App\Models\Common\Client;
use App\Models\Common\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Project extends Model
{
    use CompanyOwned;
    use SoftDeletes;
    use HasAttacments;
    use LogsActivity;

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
        'pic_client_id',
        'pic_client_name',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => ProjectStatus::class,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
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
            ]);
    }


    public function type(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class, 'project_type_id');
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_id');
    }

    public function pic_client(): BelongsTo
    {
        return $this
            ->belongsTo(Contact::class, 'pic_client_id')
            ->where([
                'contactable_type' => Client::class,
            ]);
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

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public static function generateProjectNumber(int $projectTypeId): string
    {
        $prefix = ProjectType::query()
            ->whereKey($projectTypeId)
            ->value('code') ?? 'DOC';

        $year = now()->format('y');
        $basePrefix = $prefix . $year;

        $lastDocument = self::query()
            ->where('number', 'like', "{$basePrefix}-%")
            ->orderBy('number', 'desc')
            ->first();

        $lastCounter = $lastDocument
            ? (int) substr($lastDocument->number, -3)
            : 0;

        $newCounter = $lastCounter + 1;

        return $basePrefix . '-' . str_pad($newCounter, 3, '0', STR_PAD_LEFT);
    }
}
