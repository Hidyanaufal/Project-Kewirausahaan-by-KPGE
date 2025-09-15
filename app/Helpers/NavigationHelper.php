<?php

namespace App\Helpers;

class NavigationHelper
{
    public static function filterNavigationByPermissions(string $resourceClass): array
    {
        if ($resourceClass::canAccess()) {
            return $resourceClass::getNavigationItems();
        }
        return [];
    }
}
