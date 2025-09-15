<?php

namespace App\Helpers;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;

class AuthHelper
{
    public static function currentUser()
    {
        $guard = Filament::auth();
        return $guard->user()->id;
    }

    public static function role()
    {
        $guard = Filament::auth();
        return $guard->user()->role;
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
}
