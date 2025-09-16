<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;

trait HasPermissionCheck
{
    protected static array $cachedPermissions = [];

    protected static function checkPermission(string $permission): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        if (auth()->user()->is_super_admin) {
            return true;
        }

        $userId = $user->id;

        if (!isset(static::$cachedPermissions[$userId])) {
            static::$cachedPermissions[$userId] = $user->getAllPermissions()->pluck('name')->toArray();
        }

        $permissionResult = in_array($permission, static::$cachedPermissions[$userId]) && $user->hasPermissionTo($permission);
        return $permissionResult;
    }

    public static function can(string $action, ?Model $record = null): bool
    {
        $permissionName = static::getPermissionName();

        // Kalau permission name diawali 'page_', langsung pakai permissionName tanpa prefix
        if (str_starts_with($permissionName, 'page_')) {
            $permission = $permissionName;
        } elseif (str_starts_with($permissionName, 'widget_')) {
            $permission = $permissionName;
        } else {
            $permission = $action . '_' . $permissionName;
        }

        return static::checkPermission($permission);
    }

    public static function canAccess(): bool
    {
        return static::can('view');
    }

    public static function canViewAny(): bool
    {
        return static::can('view_any');
    }

    public static function canCreate(): bool
    {
        return static::can('create');
    }

    public static function canEdit(?Model $record = null): bool
    {
        return static::can('update', $record);
    }

    public static function canDelete(?Model $record = null): bool
    {
        return static::can('delete', $record);
    }

    public static function canDeleteAny(): bool
    {
        return static::can('delete_any');
    }

    public static function canForceDelete(?Model $record = null): bool
    {
        return static::can('force_delete', $record);
    }

    public static function canForceDeleteAny(): bool
    {
        return static::can('force_delete_any');
    }

    public static function canReorder(): bool
    {
        return static::can('reorder');
    }

    public static function canReplicate(?Model $record = null): bool
    {
        return static::can('replicate', $record);
    }

    public static function canRestore(?Model $record = null): bool
    {
        return static::can('restore', $record);
    }

    public static function canRestoreAny(): bool
    {
        return static::can('restore_any');
    }

    public static function canView(?Model $record = null): bool
    {
        return static::can('view', $record);
    }

    /**
     * Get permission name based on class namespace and name.
     * Supports Resources and Pages naming conventions.
     *
     * @return string
     */
    protected static function getPermissionName(): string
    {
        $className = static::class;

        // Kalau page, ambil nama class dengan prefix 'page_'
        if (str_contains($className, '\Pages\\')) {
            $pageName = class_basename($className);
            return 'page_' . $pageName;
        }

        // Kalau widget, ambil nama class dengan prefix 'widget_'
        if (str_contains($className, '\Widgets\\')) {
            $widgetName = class_basename($className);
            return 'widget_' . $widgetName;
        }


        $pattern = '/.*\\\Resources\\\(.*)\\\\(.*)Resource$/';

        if (preg_match($pattern, $className, $matches)) {
            $moduleParts = [];

            if (!empty($matches[1])) {
                $moduleParts = preg_split('/(?=[A-Z])/', $matches[1], -1, PREG_SPLIT_NO_EMPTY);
            }

            $module = strtolower(implode('::', $moduleParts));

            $resourceKebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $matches[2]));

            $resource = str_replace('-', '::', $resourceKebab);

            return $module ? ($module . '::' . $resource) : $resource;
        }

        return '';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
