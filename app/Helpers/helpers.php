<?php

use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Setting\NumberFormat;
use App\Models\Setting\Localization;
use App\Services\CompanySettingsService;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

if (! function_exists('generateJsCode')) {
    function generateJsCode(string $precision, ?string $currency = null): string
    {
        $decimal_mark = currency($currency)->getDecimalMark();
        $thousands_separator = currency($currency)->getThousandsSeparator();

        return "\$money(\$input, '" . $decimal_mark . "', '" . $thousands_separator . "', " . $precision . ');';
    }
}

if (! function_exists('generatePercentJsCode')) {
    function generatePercentJsCode(string $format, int $precision): string
    {
        [$decimal_mark, $thousands_separator] = NumberFormat::from($format)->getFormattingParameters();

        return "\$money(\$input, '" . $decimal_mark . "', '" . $thousands_separator . "', " . $precision . ');';
    }
}

if (! function_exists('moneyMask')) {
    function moneyMask(?string $currency = null): RawJs
    {
        $precision = currency($currency)->getPrecision();

        return RawJs::make(generateJsCode($precision, $currency));
    }
}

if (! function_exists('percentMask')) {
    function percentMask(int $precision = 4): RawJs
    {
        $format = Localization::firstOrFail()->number_format->value;

        return RawJs::make(generatePercentJsCode($format, $precision));
    }
}

if (! function_exists('ratePrefix')) {
    function ratePrefix($computation, ?string $currency = null): ?string
    {
        $computationEnum = AdjustmentComputation::parse($computation);
        $localization = Localization::firstOrFail();

        if ($computationEnum->isFixed() && currency($currency)->isSymbolFirst()) {
            return currency($currency)->getPrefix();
        }

        if ($computationEnum->isPercentage() && $localization->percent_first) {
            return '%';
        }

        return null;
    }
}

if (! function_exists('rateSuffix')) {
    function rateSuffix($computation, ?string $currency = null): ?string
    {
        $computationEnum = AdjustmentComputation::parse($computation);
        $localization = Localization::firstOrFail();

        if ($computationEnum->isFixed() && ! currency($currency)->isSymbolFirst()) {
            return currency($currency)->getSuffix();
        }

        if ($computationEnum->isPercentage() && ! $localization->percent_first) {
            return '%';
        }

        return null;
    }
}

if (! function_exists('rateMask')) {
    function rateMask($computation, ?string $currency = null): ?RawJs
    {
        $computationEnum = AdjustmentComputation::parse($computation);

        if ($computationEnum->isPercentage()) {
            return percentMask(4);
        }

        if ($computationEnum->isFixed()) {
            $precision = currency($currency)->getPrecision();

            return RawJs::make(generateJsCode($precision, $currency));
        }

        return null;
    }
}

if (! function_exists('rateFormat')) {
    function rateFormat($state, $computation, ?string $currency = null): ?string
    {
        if (blank($state)) {
            return null;
        }

        $computationEnum = AdjustmentComputation::parse($computation);
        $localization = Localization::firstOrFail();

        if ($computationEnum->isPercentage() && $localization->percent_first) {
            return '%' . $state;
        }

        if ($computationEnum->isPercentage() && ! $localization->percent_first) {
            return $state . '%';
        }

        if ($computationEnum->isFixed()) {
            return money($state, $currency, true)->formatWithCode();
        }

        return null;
    }
}

if (! function_exists('is_demo_environment')) {
    function is_demo_environment(): bool
    {
        return app()->environment('demo');
    }
}

if (! function_exists('company_today')) {
    function company_today(): Carbon
    {
        return today(CompanySettingsService::getDefaultTimezone());
    }
}

if (! function_exists('company_now')) {
    function company_now(): Carbon
    {
        return now(CompanySettingsService::getDefaultTimezone());
    }
}

if (! function_exists('form_merge')) {
    function form_merge(
        Model $record,
        array $relations = []
    ): array {
        $data = $record->only($record->getFillable());

        foreach ($relations as $relation) {
            $related = $record->$relation;
            if ($related instanceof Model) {
                // hasOne / belongsTo
                $data = array_merge($data, $related->toArray());
            } elseif ($related instanceof \Illuminate\Support\Collection) {
                // hasMany / morphMany
                $data[$relation] = $related->map->toArray()->all();
            }
        }

        return $data;
    }
}

if (! function_exists('relation_create')) {
    /**
     * Create a root model and all its related models automatically.
     *
     * @param  string  $root  The root model class
     * @param  array  $data  Input data from form
     * @param  array  $relations  Key: relation name in root model, Value: related model class
     * @return \Illuminate\Database\Eloquent\Model The created root model instance
     */
    function relation_create(
        string $root,
        array $data,
        array $relations = []
    ) {
        return DB::transaction(function () use ($root, $data, $relations) {
            $rootInstance = $root::create(Arr::only($data, (new $root)->getFillable()));
            foreach ($relations as $relationName => $modelClass) {
                $rootInstance->$relationName()->create(
                    Arr::only($data, (new $modelClass)->getFillable())
                );
            }

            return $rootInstance;
        });
    }
}

if (! function_exists('relation_update')) {
    /**
     * Update a root model and all its related models automatically.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $root  The root model instance
     * @param  array  $data  Input data from form
     * @param  array  $relations  Key: relation name in root model, Value: related model class
     * @return \Illuminate\Database\Eloquent\Model The updated root model instance
     */
    function relation_update(
        $root,
        array $data,
        array $relations = []
    ) {
        return DB::transaction(function () use ($root, $data, $relations) {
            // update root
            $root->update(Arr::only($data, $root->getFillable()));

            foreach ($relations as $relationName => $modelClass) {
                $relationData = Arr::only($data, (new $modelClass)->getFillable());

                // cek apakah sudah ada data di relasi
                $relation = $root->$relationName()->first();

                if ($relation) {
                    // kalau sudah ada → update
                    $relation->update($relationData);
                } else {
                    // kalau belum ada → create
                    if ($relationName === 'offering') {
                        $relationData['sellable'] = 1;
                        $relationData['purchasable'] = 1;
                    }
                    $root->$relationName()->create($relationData);
                }
            }

            return $root;
        });
    }
}

if (! function_exists('default_company_id')) {
    function default_company_id()
    {
        return 1;
    }
}

if (! function_exists('extract_iframe_src')) {
    function extract_iframe_src(string $iframe): ?string
    {
        if (preg_match('/src="([^"]+)"/', $iframe, $matches)) {
            return $matches[1];
        }

        return null;
    }
}

if (! function_exists('formatCurrencyShort')) {
    function formatCurrencyShort(string $value): string
    {
        // Bersihkan format Rp
        $numeric = str_replace(['Rp', '.', ' '], '', $value);
        $numeric = str_replace(',', '.', $numeric); // ubah koma ke titik untuk desimal
        $numeric = (float) $numeric; // convert ke float

        if ($numeric >= 1_000_000_000_000) {
            return 'Rp' . number_format($numeric / 1_000_000_000_000, 2, ',', '.') . ' T';
        } elseif ($numeric >= 1_000_000_000) {
            return 'Rp' . number_format($numeric / 1_000_000_000, 2, ',', '.') . ' M';
        } elseif ($numeric >= 1_000_000) {
            return 'Rp' . number_format($numeric / 1_000_000, 2, ',', '.') . ' Jt';
        }

        return 'Rp' . number_format($numeric, 0, ',', '.');
    }
}

if (! function_exists('formatMoneyRoundWhole')) {
    function formatMoneyRoundWhole(?string $formattedMoney): ?string
    {
        if (! $formattedMoney) {
            return null;
        }

        $numeric = str_replace(['Rp', ' '], '', $formattedMoney);
        $numeric = str_replace('.', '', $numeric);
        $numeric = str_replace(',', '.', $numeric);
        $numeric = (float) $numeric;

        $rounded = (int) round($numeric, 0, PHP_ROUND_HALF_UP);

        return 'Rp' . number_format($rounded, 0, ',', '.');
    }
}

if (! function_exists('getRomanMonth')) {
    function getRomanMonth(int $month): string
    {
        $romans = [
            'I',
            'II',
            'III',
            'IV',
            'V',
            'VI',
            'VII',
            'VIII',
            'IX',
            'X',
            'XI',
            'XII',
        ];

        return $romans[$month - 1];
    }
}
