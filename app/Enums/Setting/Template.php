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

    public const KPP = self::Kppb->value;

    public function getLabel(): ?string
    {
        return translate($this->name);
    }
}
