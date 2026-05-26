<?php

namespace App\Services;

use App\Enums\Addons\RoleDefault;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class NotificationRecipientService
{
    /**
     * Resolve notification recipients for the given company.
     *
     * @return array{users: Collection<int, User>, source: string|null, resolved_company_id: int|null}
     */
    public function getAdminRecipientResolutionForCompany(int $companyId): array
    {
        $targetAdmins = $this->getAdminUsersByRoleCompany($companyId);
        if ($targetAdmins->isNotEmpty()) {
            return [
                'users' => $targetAdmins,
                'source' => 'target_company_admin',
                'resolved_company_id' => $companyId,
            ];
        }

        if ($companyId !== 1) {
            $fallbackCompanyAdmins = $this->getAdminUsersByRoleCompany(1);
            if ($fallbackCompanyAdmins->isNotEmpty()) {
                return [
                    'users' => $fallbackCompanyAdmins,
                    'source' => 'fallback_company_1_admin',
                    'resolved_company_id' => 1,
                ];
            }
        }

        $superAdmins = $this->getGlobalSuperAdmins();
        if ($superAdmins->isNotEmpty()) {
            return [
                'users' => $superAdmins,
                'source' => 'fallback_superadmin',
                'resolved_company_id' => null,
            ];
        }

        return [
            'users' => collect(),
            'source' => null,
            'resolved_company_id' => null,
        ];
    }

    /**
     * Get all users that can receive admin notifications for the given company.
     *
     * @return Collection<int, User>
     */
    public function getAdminUsersForCompany(int $companyId): Collection
    {
        return $this->getAdminRecipientResolutionForCompany($companyId)['users'];
    }

    /**
     * Resolve notification recipients for the company of the given model (e.g. Project, Estimate).
     *
     * @return array{users: Collection<int, User>, source: string|null, resolved_company_id: int|null}
     */
    public function getAdminRecipientResolutionFor(Company|int $companyOrId): array
    {
        $companyId = $companyOrId instanceof Company
            ? $companyOrId->id
            : $companyOrId;

        return $this->getAdminRecipientResolutionForCompany($companyId);
    }

    /**
     * Get admin users for the company of the given model (e.g. Project, Estimate).
     *
     * @return Collection<int, User>
     */
    public function getAdminUsersFor(Company|int $companyOrId): Collection
    {
        return $this->getAdminRecipientResolutionFor($companyOrId)['users'];
    }

    /**
     * @return Collection<int, User>
     */
    protected function getAdminUsersByRoleCompany(int $companyId): Collection
    {
        $adminRole = Role::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('name', RoleDefault::Admin->value)
            ->first();

        if (! $adminRole) {
            return collect();
        }

        $userIds = DB::table('model_has_roles')
            ->where('role_id', $adminRole->id)
            ->where('company_id', $companyId)
            ->where('model_type', User::class)
            ->pluck('model_id')
            ->unique()
            ->filter()
            ->values();

        return $this->getUsersByIds($userIds);
    }

    /**
     * @return Collection<int, User>
     */
    protected function getGlobalSuperAdmins(): Collection
    {
        $userIds = User::query()
            ->where('is_super_admin', true)
            ->pluck('id')
            ->unique()
            ->filter()
            ->values();

        return $this->getUsersByIds($userIds);
    }

    /**
     * @param  Collection<int, int|string>  $userIds
     * @return Collection<int, User>
     */
    protected function getUsersByIds(Collection $userIds): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }

        $users = User::query()
            ->whereIn('id', $userIds->all())
            ->get()
            ->keyBy('id');

        return $userIds
            ->map(fn ($userId) => $users->get((int) $userId))
            ->filter()
            ->values();
    }
}
