<?php

namespace App\Concerns;

use Filament\Forms\Components\Select;
use Illuminate\Support\Str;

trait HasOptions
{
    public static function getOptionKey(): string
    {
        $class = class_basename(static::$model ?? static::class);
        return Str::snake($class) . '_id';
    }

    public static function getOptionLabel(): string
    {
        $class = class_basename(static::$model ?? static::class);
        return Str::headline($class);
    }

    public static function getOptionName(): string
    {
        return 'name';
    }

    public static function getOptionId(): string
    {
        return 'id';
    }

    public static function options(
        ?string $key = null,
        ?string $label = null,
        ?string $name = null,
        ?string $id = null
    ): Select {
        $key   = $key   ?? static::getOptionKey();
        $label = $label ?? static::getOptionLabel();
        $name  = $name  ?? static::getOptionName();
        $id    = $id    ?? static::getOptionId();

        $modalHeading = __('filament-forms::components.select.actions.create_option.modal.heading') . ' ' . $label;

        return Select::make($key)
            ->label($label)
            ->createOptionModalHeading($modalHeading)
            ->options(function () use ($name, $id) {
                $query = static::getModel()::query();

                if (Str::contains($name, '.')) {
                    // Support relasi, contoh "user.name"
                    [$relation, $column] = explode('.', $name, 2);
                    return $query->with($relation)
                        ->get()
                        ->mapWithKeys(fn($item) => [$item->$id => $item->$relation?->$column])
                        ->filter();
                }

                return $query->pluck($name, $id);
            })
            ->preload()
            ->searchable()
            ->required();
    }
}
