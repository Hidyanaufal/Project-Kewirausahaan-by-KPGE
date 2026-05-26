<?php

use App\Enums\Addons\RoleDefault;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationRecipientService;
use Illuminate\Support\Facades\DB;

function assignAdminRoleToUser(int $companyId, User $user): void
{
    $role = Role::withoutGlobalScopes()->create([
        'name' => RoleDefault::Admin->value,
        'guard_name' => 'web',
        'company_id' => $companyId,
    ]);

    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => User::class,
        'model_id' => $user->id,
        'company_id' => $companyId,
    ]);
}

test('resolves target company admins before applying fallbacks', function () {
    /** @var \Tests\TestCase $this */
    $companyId = $this->testCompany->id;
    $adminUser = User::factory()->create();

    assignAdminRoleToUser($companyId, $adminUser);

    $resolution = app(NotificationRecipientService::class)->getAdminRecipientResolutionFor($companyId);

    expect($resolution['source'])->toBe('target_company_admin')
        ->and($resolution['resolved_company_id'])->toBe($companyId)
        ->and($resolution['users']->pluck('id')->all())->toBe([$adminUser->id]);
});

test('falls back to company 1 admins when target company has none', function () {
    $fallbackAdmin = User::factory()->create();

    assignAdminRoleToUser(1, $fallbackAdmin);

    $resolution = app(NotificationRecipientService::class)->getAdminRecipientResolutionFor(999);

    expect($resolution['source'])->toBe('fallback_company_1_admin')
        ->and($resolution['resolved_company_id'])->toBe(1)
        ->and($resolution['users']->pluck('id')->all())->toBe([$fallbackAdmin->id]);
});

test('falls back to global superadmins when target and company 1 admins are missing', function () {
    $superAdminA = User::factory()->create([
        'is_super_admin' => true,
    ]);
    $superAdminB = User::factory()->create([
        'is_super_admin' => true,
    ]);

    $resolution = app(NotificationRecipientService::class)->getAdminRecipientResolutionFor(999);

    expect($resolution['source'])->toBe('fallback_superadmin')
        ->and($resolution['resolved_company_id'])->toBeNull()
        ->and($resolution['users']->pluck('id')->sort()->values()->all())
        ->toBe([$superAdminA->id, $superAdminB->id]);
});

test('returns a single recipient when fallback company 1 admin is also a superadmin', function () {
    $fallbackAdmin = User::factory()->create([
        'is_super_admin' => true,
    ]);

    assignAdminRoleToUser(1, $fallbackAdmin);

    $resolution = app(NotificationRecipientService::class)->getAdminRecipientResolutionFor(999);

    expect($resolution['source'])->toBe('fallback_company_1_admin')
        ->and($resolution['resolved_company_id'])->toBe(1)
        ->and($resolution['users']->pluck('id')->all())->toBe([$fallbackAdmin->id])
        ->and($resolution['users'])->toHaveCount(1);
});
