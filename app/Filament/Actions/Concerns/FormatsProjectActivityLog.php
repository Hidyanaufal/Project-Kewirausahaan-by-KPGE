<?php

namespace App\Filament\Actions\Concerns;

use App\Utilities\Currency\CurrencyConverter;

trait FormatsProjectActivityLog
{
    protected function formatProjectActivityProperties(array $properties): array
    {
        foreach (['attributes', 'old'] as $key) {
            if (isset($properties[$key]['project_value']) && is_numeric($properties[$key]['project_value'])) {
                $properties[$key]['project_value'] = CurrencyConverter::formatCentsToMoney(
                    (int) $properties[$key]['project_value']
                );
            }
        }

        return $properties;
    }
}
