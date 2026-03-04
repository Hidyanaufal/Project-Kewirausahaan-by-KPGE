<?php

namespace App\Casts;

use App\Enums\Accounting\AdjustmentComputation;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\RateCalculator;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class RateCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): string
    {
        if (! $value) {
            return '0';
        }

        $currencyCode = $attributes['currency_code'] ?? $this->getDefaultCurrencyCode();
        $computation = $this->getComputationForKey($key, $attributes);

        if ($computation?->isFixed()) {
            return money($value, $currencyCode)->formatSimple();
        }

        return RateCalculator::formatScaledRate($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        if (! $value) {
            return 0;
        }

        if (is_int($value)) {
            return $value;
        }

        $computation = $this->getComputationForKey($key, $attributes);

        $currencyCode = $attributes['currency_code'] ?? $this->getDefaultCurrencyCode();

        if ($computation?->isFixed()) {
            return money($value, $currencyCode, true)->getAmount();
        }

        return RateCalculator::parseLocalizedRate($value);
    }

    private function getComputationForKey(string $key, array $attributes): ?AdjustmentComputation
    {
        return match ($key) {
            'profit_rate' => AdjustmentComputation::parse($attributes['profit_computation'] ?? null),
            'discount_rate' => AdjustmentComputation::parse($attributes['discount_computation'] ?? null),
            default => AdjustmentComputation::parse($attributes['computation'] ?? null),
        };
    }

    private function getDefaultCurrencyCode(): string
    {
        return CurrencyAccessor::getDefaultCurrency();
    }
}
