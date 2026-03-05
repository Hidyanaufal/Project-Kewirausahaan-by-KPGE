<?php

namespace App\Concerns;

use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Models\Accounting\Bill;
use App\Models\Accounting\DocumentLineItem;
use App\Models\Accounting\Estimate;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use App\Utilities\RateCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

trait ManagesLineItems
{
    protected function handleLineItems(Model $record, Collection $lineItems): void
    {
        foreach ($lineItems as $index => $itemData) {
            $lineItem = isset($itemData['id'])
                ? $record->lineItems->find($itemData['id'])
                : $record->lineItems()->make();

            $lineItem->fill([
                'offering_id' => $itemData['offering_id'],
                'description' => $itemData['description'],
                'quantity' => $itemData['quantity'],
                'unit' => $itemData['unit'] ?? 'pcs',
                'unit_price' => $itemData['unit_price'],
                'line_number' => $index + 1,
            ]);

            if (! $lineItem->exists) {
                $lineItem->documentable()->associate($record);
            }

            $lineItem->save();

            $this->handleLineItemAdjustments($lineItem, $itemData, $record->discount_method ?? DocumentDiscountMethod::PerDocument);
            $this->updateLineItemTotals($lineItem, $record->discount_method ?? DocumentDiscountMethod::PerDocument);
        }
    }

    protected function deleteRemovedLineItems(Model $record, Collection $lineItems): void
    {
        $existingLineItemIds = $record->lineItems->pluck('id');
        $updatedLineItemIds = $lineItems->pluck('id')->filter();
        $lineItemsToDelete = $existingLineItemIds->diff($updatedLineItemIds);

        if ($lineItemsToDelete->isNotEmpty()) {
            $record
                ->lineItems()
                ->whereIn('id', $lineItemsToDelete)
                ->each(fn(DocumentLineItem $lineItem) => $lineItem->delete());
        }
    }

    protected function handleLineItemAdjustments(
        DocumentLineItem $lineItem,
        array $itemData,
        DocumentDiscountMethod $discountMethod
    ): void {
        $isBill = $lineItem->documentable instanceof Bill;

        $taxType = $isBill ? 'purchaseTaxes' : 'salesTaxes';
        $discountType = $isBill ? 'purchaseDiscounts' : 'salesDiscounts';

        $adjustmentIds = collect($itemData[$taxType] ?? [])
            ->merge($discountMethod->isPerLineItem() ? ($itemData[$discountType] ?? []) : [])
            ->filter()
            ->unique();

        $lineItem->adjustments()->sync($adjustmentIds);
        $lineItem->refresh();
    }

    protected function updateLineItemTotals(DocumentLineItem $lineItem, DocumentDiscountMethod $discountMethod): void
    {
        $lineItem->updateQuietly([
            'tax_total' => $lineItem->calculateTaxTotalAmount(),
            'discount_total' => $discountMethod->isPerLineItem()
                ? $lineItem->calculateDiscountTotalAmount()
                : 0,
        ]);
    }

    protected function updateDocumentTotals(Model $record, array $data): array
    {
        $data['discount_method'] = $data['discount_method'] ?? DocumentDiscountMethod::PerDocument;
        $currencyCode = $data['currency_code'] ?? $record->currency_code ?? CurrencyAccessor::getDefaultCurrency();
        $subtotalCents = $record->lineItems()->sum('subtotal');
        $taxTotalCents = $record->lineItems()->sum('tax_total');
        $discountTotalCents = $this->calculateDiscountTotal(
            DocumentDiscountMethod::parse($data['discount_method']),
            AdjustmentComputation::parse($data['discount_computation']),
            $data['discount_rate'] ?? null,
            $subtotalCents,
            $record,
            $currencyCode,
        );
        $profitTotal = $this->calculateProfitTotal(
            $record,
            $subtotalCents,
            $currencyCode,
            $data,
        );

        if ($record instanceof \App\Models\Accounting\Invoice) {
            $baseForTax = $subtotalCents + $profitTotal - $discountTotalCents;
            $taxTotalCents = (int) round(0.11 * $baseForTax);
        }

        $grandTotalCents = $subtotalCents + $profitTotal - $discountTotalCents + $taxTotalCents;

        return [
            'subtotal' => $subtotalCents,
            'tax_total' => $taxTotalCents,
            'discount_total' => $discountTotalCents,
            'profit_total' => $profitTotal,
            'total' => $grandTotalCents,
        ];
    }

    protected function calculateProfitTotal(
        Model $record,
        int $subtotalCents,
        string $currencyCode,
        array $data = []
    ): int {
        $result = 0;
        // Gunakan nilai dari form ($data) saat update agar profit_rate terbaru (mis. 12%) dipakai, bukan nilai lama dari DB
        $profitComputation = AdjustmentComputation::parse($data['profit_computation'] ?? $record->profit_computation) ?? AdjustmentComputation::Percentage;
        $profitRate = $data['profit_rate'] ?? $record->profit_rate ?? 0;
        $profitRate = blank($profitRate) ? '0' : (string) $profitRate;

        if ($profitComputation->isPercentage()) {
            $scaledProfitRate = RateCalculator::parseLocalizedRate($profitRate);
            $result = RateCalculator::calculatePercentage($subtotalCents, $scaledProfitRate);
        } else {
            if (! CurrencyConverter::isValidAmount($profitRate, $currencyCode)) {
                $profitRate = '0';
            }
            $result = CurrencyConverter::convertToCents($profitRate, $currencyCode);
        }

        return $result;
    }

    protected function calculateDiscountTotal(
        DocumentDiscountMethod $discountMethod,
        ?AdjustmentComputation $discountComputation,
        ?string $discountRate,
        int $subtotalCents,
        Model $record,
        string $currencyCode
    ): int {
        if ($discountMethod->isPerLineItem()) {
            return $record->lineItems()->sum('discount_total');
        }

        if ($discountComputation?->isPercentage()) {
            $scaledRate = RateCalculator::parseLocalizedRate($discountRate);

            return RateCalculator::calculatePercentage($subtotalCents, $scaledRate);
        }

        return CurrencyConverter::convertToCents($discountRate, $currencyCode);
    }
}
