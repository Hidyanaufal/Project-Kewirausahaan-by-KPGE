<?php

namespace App\Concerns;

use Filament\Support\Enums\MaxWidth;

trait HasMediumContentWidth
{
    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return MaxWidth::FourExtraLarge;
    }
}
