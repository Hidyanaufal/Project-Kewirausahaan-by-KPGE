<?php

namespace App\Enums\Setting;

use App\Enums\Concerns\ParsesEnum;
use Filament\Support\Contracts\HasLabel;

enum Template: string implements HasLabel
{
    use ParsesEnum;

    case Jes = 'jes';
    case Kppb = 'kppb';
    case Kpg = 'kpg';
    case Kppe = 'kppe';
    case Default = 'default';
    case Classic = 'classic';
    case Modern = 'modern';
    case CustomInvoice = 'custom.invoice';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Jes => 'JES',
            self::Kppb => 'KPPB',
            self::Kpg => 'KPG',
            self::Kppe => 'KPPE',
            self::Default => 'Default',
            self::Classic => 'Classic',
            self::Modern => 'Modern',
            self::CustomInvoice => 'Custom Invoice',
        };
    }
}
