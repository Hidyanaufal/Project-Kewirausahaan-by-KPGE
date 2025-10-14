<?php

namespace App\Enums\Addons;

use Filament\Support\Contracts\HasLabel;

enum DepreciationMethod: string implements HasLabel
{
    case StraightLine       = 'garis_lurus';
    case DecliningBalance   = 'saldo_menurun';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::StraightLine      => 'Garis Lurus',
            self::DecliningBalance  => 'Saldo Menurun',
        };
    }
}
