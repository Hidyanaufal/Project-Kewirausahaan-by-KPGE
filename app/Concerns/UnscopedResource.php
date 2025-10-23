<?php

namespace App\Concerns;

use App\Scopes\CurrentCompanyScope;
use Illuminate\Database\Eloquent\Builder;

trait UnscopedResource
{
    public static function isScopedToTenant(): bool
    {
        return false;
    }

    protected static array $globalScopesToRemove = [
        CurrentCompanyScope::class,
    ];

    protected static function applyWithoutGlobalScopes(Builder $query): Builder
    {
        $scopes = static::$globalScopesToRemove ?? [];

        return empty($scopes)
            ? $query
            : $query->withoutGlobalScopes($scopes);
    }

    public static function getEloquentQuery(): Builder
    {
        /** @var Builder $query */
        $query = parent::getEloquentQuery();

        return static::applyWithoutGlobalScopes($query);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        /** @var Builder $query */
        $query = parent::getGlobalSearchEloquentQuery();

        return static::applyWithoutGlobalScopes($query);
    }

    public static function getTableQuery(): Builder
    {
        /** @var Builder $query */
        $query = parent::getTableQuery();

        return static::applyWithoutGlobalScopes($query);
    }

    public static function relationQueryModifier(): \Closure
    {
        return fn(Builder $q) => static::applyWithoutGlobalScopes($q);
    }
}
