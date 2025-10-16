<?php

namespace App\Enums\Addons;

use Filament\Support\Contracts\HasLabel;

enum AgingRange: string implements HasLabel
{
    case ONE_TO_THIRTY = '1_to_30';
    case THIRTY_ONE_TO_SIXTY = '31_to_60';
    case SIXTY_ONE_TO_NINETY = '61_to_90';
    case OVER_NINETY = 'over_90';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::ONE_TO_THIRTY => '1 to 30 days',
            self::THIRTY_ONE_TO_SIXTY => '31 to 60 days',
            self::SIXTY_ONE_TO_NINETY => '61 to 90 days',
            self::OVER_NINETY => 'Over 90 days',
        };
    }
}
