<?php

namespace App\Filament\Exports;

use App\Utilities\RateCalculator;
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
            'discount_rate',
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

    public static function getXlsxPercentCellStyle(): Style
    {
        return (new Style())
            ->setFormat('0%')
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
     * Export a document discount rate.
     * Percentages are written as "10%" then converted to a numeric Excel percent cell.
     * Fixed amounts are written as numbers.
     */
    protected static function discountRateColumn(): ExportColumn
    {
        return ExportColumn::make('discount_rate')
            ->state(function (Model $record): mixed {
                $raw = $record->getRawOriginal('discount_rate');

                if ($raw === null || $raw === '') {
                    return null;
                }

                if ($record->discount_computation?->isPercentage()) {
                    $percent = ((int) $raw) / RateCalculator::SCALING_FACTOR;
                    $formatted = rtrim(rtrim(number_format($percent, RateCalculator::PRECISION, '.', ''), '0'), '.');

                    return ($formatted === '' ? '0' : $formatted) . '%';
                }

                return self::excelMoney($raw);
            });
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

