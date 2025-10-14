<?php

namespace App\Enums\Addons;

enum AssetStatus: string
{
    case Active             = 'active';
    case Disposed           = 'disposed';
    case FullyDepreciated   = 'fully_depreciated';

    public function label(): string
    {
        return match ($this) {
            self::Active            => 'Active',
            self::Disposed          => 'Disposed',
            self::FullyDepreciated  => 'Fully Depreciated',
        };
    }
}
