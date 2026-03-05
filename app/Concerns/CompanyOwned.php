<?php

namespace App\Concerns;

use App\Models\Notification;
use App\Models\User;
use App\Scopes\CurrentCompanyScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Wallo\FilamentCompanies\FilamentCompanies;

trait CompanyOwned
{
    public static function bootCompanyOwned(): void
    {
        static::creating(static function ($model) {
            if (empty($model->company_id)) {
                $companyId = null;

                // For notifications: use only payload or notifiable — never session (queue worker session is unreliable).
                if ($model instanceof Notification) {
                    $data = $model->data ?? [];
                    if (! empty($data['company_id'])) {
                        $companyId = $data['company_id'];
                    } elseif (! empty(($data['viewData'] ?? [])['company_id'])) {
                        $companyId = $data['viewData']['company_id'];
                    } elseif ($model->notifiable_type === User::class && ! empty($model->notifiable_id)) {
                        $notifiable = User::withoutGlobalScopes()->find($model->notifiable_id);
                        if ($notifiable instanceof User && $notifiable->current_company_id) {
                            $companyId = $notifiable->current_company_id;
                        }
                    }
                }

                if (! $companyId) {
                    $companyId = session('current_company_id');
                    if (! $companyId && ($user = Auth::user()) && ($companyId = $user->current_company_id)) {
                        session(['current_company_id' => $companyId]);
                    }
                }

                if ($companyId) {
                    $model->company_id = $companyId;
                } else {
                    Log::error('CurrentCompanyScope: No company_id found for user ' . Auth::id());

                    throw new ModelNotFoundException('CurrentCompanyScope: No company_id set in the session, user, or database.');
                }
            }
        });

        static::addGlobalScope(new CurrentCompanyScope);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(FilamentCompanies::companyModel(), 'company_id');
    }
}
