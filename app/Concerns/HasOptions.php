<?php

namespace App\Concerns;

use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Str;

trait HasOptions
{
    public static function getModel(): string
    {
        return static::$model ?? throw new \Exception('Model not defined in HasOptions trait.');
    }

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

    public static function canCreateOption(): bool
    {
        return false;
    }

    public static function getFormSchema(): array
    {
        return [];
    }

    public static function options(
        ?string $key = null,
        ?string $label = null,
        ?string $name = null,
        ?string $id = null,
        ?bool $enableCreate = null,
    ): Select {
        $model          = static::$model ?? static::getModel();
        $key            = $key   ?? static::getOptionKey();
        $label          = $label ?? static::getOptionLabel();
        $name           = $name  ?? static::getOptionName();
        $id             = $id    ?? static::getOptionId();
        $enableCreate   = $enableCreate ?? static::canCreateOption();
        $modalHeading   = __('filament-forms::components.select.actions.create_option.modal.heading') . ' ' . $label;

        $select = Select::make($key)
            ->label($label)
            ->createOptionModalHeading($modalHeading)
            ->options(function () use ($name, $id) {
                $query = static::getModel()::query();

                if (Str::contains($name, '.')) {
                    [$relation, $column] = explode('.', $name, 2);  // Support relasi, contoh "user.name"
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

        if ($enableCreate) {
            $select
                ->createOptionForm(static::getFormSchema())
                ->createOptionModalHeading($modalHeading)
                ->createOptionAction(function (Action $action) {
                    $action->slideOver();
                    $action->modalWidth(MaxWidth::Small);
                })
                ->createOptionUsing(function (array $data) use ($model) {
                    $record = $model::create($data);
                    return $record->getKey();
                });
        }

        return $select;
    }
}
