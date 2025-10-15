<?php

namespace App\Helpers;

use App\Enums\Addons\RoleDefault;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;

class AuthHelper
{
    public static function user(): User
    {
        $guard = Filament::auth();
        return $guard->user();
    }

    public static function currentUser(): int
    {
        $guard = Filament::auth();
        return $guard->user()->id;
    }

    public static function currentCompany(): ?int
    {
        $companyId = session('current_company_id');
        if (! $companyId && ($user = Auth::user())) {
            $companyId = $user->current_company_id ?? null;
            if ($companyId) {
                session(['current_company_id' => $companyId]);
            }
        }
        return $companyId;
    }

    public static function isPrimaryCompany(): ?int
    {
        $companyId = static::currentCompany();
        $company = Company::find($companyId);
        return $company->is_primary;
    }

    public static function isUser(): bool
    {
        return static::user()->hasRole(RoleDefault::User->value);
    }

    public static function isAdmin(): bool
    {
        if (static::user()->is_super_admin) {
            return true;
        }

        return static::user()->hasRole(RoleDefault::Admin->value);
    }
}
