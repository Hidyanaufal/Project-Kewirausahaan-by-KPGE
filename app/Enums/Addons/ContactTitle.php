<?php

namespace App\Enums\Addons;

use Filament\Support\Contracts\HasLabel;

enum ContactTitle: string implements HasLabel
{
    case Bapak = 'bapak';
    case Ibu = 'ibu';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Bapak => 'Bapak',
            self::Ibu => 'Ibu',
        };
    }
}
