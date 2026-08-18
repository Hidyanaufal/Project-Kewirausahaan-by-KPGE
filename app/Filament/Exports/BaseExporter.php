<?php

namespace App\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use OpenSpout\Common\Entity\Style\Style;

abstract class BaseExporter extends Exporter
{
    /**
     * Column names that should be exported as numeric Excel cells.
     *
     * @return list<string>
     */
    public static function getMoneyColumnNames(): array
    {
        return [
            'total',
            'amount_paid',
            'amount_due',
            'subtotal',
            'tax_total',
            'discount_total',
            'profit_total',
            'debit',
            'credit',
            'amount',
            'balance',
            'overdue_amount',
            'project_value',
            'approved_invoice_total_without_ppn_sum',
            'approved_bill_total_sum',
            'total_invoice_without_ppn',
            'total_biaya',
            'net_earning',
            'opening_fiscal_book_value',
            'depreciation_expense',
            'closing_fiscal_book_value',
        ];
    }

    public static function getXlsxMoneyCellStyle(): Style
    {
        return (new Style())
            ->setFormat('#,##0.00')
            ->setShouldWrapText(false);
    }

    protected static function moneyColumn(string $name, ?string $label = null): ExportColumn
    {
        $column = ExportColumn::make($name)
            ->state(function (Model $record) use ($name): ?float {
                $raw = $record->getRawOriginal($name);

                if ($raw === null) {
                    $raw = $record->getAttribute($name);
                }

                return self::excelMoney($raw);
            });

        if ($label !== null) {
            $column->label($label);
        }

        return $column;
    }

    /**
     * Normalize a money-like value into a numeric amount for Excel.
     * - Money object (has getValue()) -> getValue()
     * - int (assumed minor units / cents) -> / 100
     * - numeric string (cents from PDO) -> / 100
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

        if (is_numeric($value)) {
            return ((int) $value) / 100;
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

