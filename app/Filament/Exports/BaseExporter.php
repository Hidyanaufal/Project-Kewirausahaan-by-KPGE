<?php

namespace App\Filament\Exports;

use Filament\Actions\Exports\Exporter;
use OpenSpout\Common\Entity\Style\Style;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Facades\Auth;

abstract class BaseExporter extends Exporter
{
    /**
     * Normalize a money-like value into a numeric amount for Excel.
     * - Money object (has getValue()) -> getValue()
     * - int (assumed minor units / cents) -> / 100
     * - float -> as-is
     */
    protected static function excelMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_object($value) && method_exists($value, 'getValue')) {
            return (float) $value->getValue();
        }

        if (is_int($value)) {
            return $value / 100;
        }

        if (is_float($value)) {
            return $value;
        }

        return null;
    }

    public function getXlsxCellStyle(): ?Style
    {
        return (new Style())
            ->setShouldWrapText(false);
    }

    public function getXlsxHeaderCellStyle(): ?Style
    {
        return (new Style())
            ->setFontBold()
            ->setShouldWrapText(false);
    }

    public function getFileName(Export $export): string
    {
        $companyName = Auth::user()?->currentCompany?->name ?? 'Company';
        $model = (string) str(static::getModel())
            ->classBasename()
            ->beforeLast('Exporter')
            ->kebab()
            ->replace('-', ' ')
            ->plural();

        $timestamp = now()->format('Y-m-d_H-i-s');

        return "{$companyName} {$model} {$timestamp}";
    }
}

