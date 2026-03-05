<?php

namespace App\Services;

use App\Enums\Addons\RoleDefault;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationRecipientService
{
    /**
     * Get all users that have Administrator role in the given company.
     * Includes users with role Administrator (via Spatie model_has_roles) and
     * super admins that belong to the company.
     *
     * @return Collection<int, User>
     */
    public function getAdminUsersForCompany(int $companyId): Collection
    {
        $adminRole = Role::where('company_id', $companyId)
            ->where('name', RoleDefault::Admin->value)
            ->first();

        $userIds = collect();

        if ($adminRole) {
            $userIds = $userIds->merge(
                \Illuminate\Support\Facades\DB::table('model_has_roles')
                    ->where('role_id', $adminRole->id)
                    ->where('company_id', $companyId)
                    ->where('model_type', User::class)
                    ->pluck('model_id')
            );
        }

        $superAdminIds = User::query()
            ->where('is_super_admin', true)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId))
            ->pluck('id');

        $userIds = $userIds->merge($superAdminIds)->unique()->filter();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->get();
    }

    /**
     * Get admin users for the company of the given model (e.g. Project, Estimate).
     */
    public function getAdminUsersFor(Company|int $companyOrId): Collection
    {
        $companyId = $companyOrId instanceof Company
            ? $companyOrId->id
            : $companyOrId;

        return $this->getAdminUsersForCompany($companyId);
    }
}
