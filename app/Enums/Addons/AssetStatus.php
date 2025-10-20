<?php

namespace App\Enums\Addons;

use Filament\Support\Contracts\HasLabel;

enum AssetStatus: string implements HasLabel
{
    case Active             = 'active';
    case Disposed           = 'disposed';
    case FullyDepreciated   = 'fully_depreciated';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Active            => 'Active',
            self::Disposed          => 'Disposed',
            self::FullyDepreciated  => 'Fully Depreciated',
        };
    }
}
