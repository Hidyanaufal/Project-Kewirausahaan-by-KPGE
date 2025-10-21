<?php

namespace App\View\Models;

use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\DocumentType;
use App\Models\Accounting\Adjustment;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use App\Utilities\RateCalculator;
use Illuminate\Support\Number;

class DocumentTotalViewModel
{
    public function __construct(
        public ?array $data,
        public DocumentType $documentType = DocumentType::Invoice,
    ) {}

    public function buildViewData(): array
    {
        $currencyCode = $this->data['currency_code'] ?? CurrencyAccessor::getDefaultCurrency();
        $defaultCurrencyCode = CurrencyAccessor::getDefaultCurrency();

        $lineItems = collect($this->data['lineItems'] ?? []);

        $subtotalInCents = $lineItems->sum(fn($item) => $this->calculateLineSubtotalInCents($item, $currencyCode));
        $taxTotalInCents = $this->calculateAdjustmentsTotalInCents($lineItems, $this->documentType->getTaxKey(), $currencyCode);
        $discountTotalInCents = $this->calculateDiscountTotalInCents($lineItems, $subtotalInCents, $currencyCode);
        $profitTotalInCents = $this->calculateProfitTotalInCents($subtotalInCents, $currencyCode) ?? 0;

        $discountMethod = DocumentDiscountMethod::parse($this->data['discount_method']);
        $isPerDocumentDiscount = $discountMethod->isPerDocument();

        $taxTotal = $taxTotalInCents > 0
            ? CurrencyConverter::formatCentsToMoney($taxTotalInCents, $currencyCode)
            : null;

        $profitTotal = CurrencyConverter::formatCentsToMoney($profitTotalInCents, $currencyCode);

        if ($this->documentType == DocumentType::Invoice) {
            if ($profitTotalInCents) {
                $taxTotalInCents += $profitTotalInCents * 0.11;
                $taxTotal = $taxTotalInCents > 0
                    ? CurrencyConverter::formatCentsToMoney($taxTotalInCents, $currencyCode)
                    : null;
            }
        }

        $discountTotal = ($isPerDocumentDiscount || $discountTotalInCents > 0)
            ? CurrencyConverter::formatCentsToMoney($discountTotalInCents, $currencyCode)
            : null;


        $subtotal = ($taxTotal || $discountTotal || $profitTotal)
            ? CurrencyConverter::formatCentsToMoney($subtotalInCents, $currencyCode)
            : null;

        $grandTotalInCents = $subtotalInCents + $profitTotalInCents + ($taxTotalInCents - $discountTotalInCents);
        $grandTotal = CurrencyConverter::formatCentsToMoney($grandTotalInCents, $currencyCode);

        $amountDueInCents = $this->calculateAmountDueInCents($grandTotalInCents, $currencyCode);
        $conversionMessage = $this->buildConversionMessage($grandTotalInCents, $currencyCode, $defaultCurrencyCode);

        $amountDue = $this->documentType !== DocumentType::Estimate
            ? CurrencyConverter::formatCentsToMoney($amountDueInCents, $currencyCode)
            : null;

        return [
            'subtotal' => $subtotal,
            'taxTotal' => $taxTotal,
            'discountTotal' => $discountTotal,
            'profitTotal' => $profitTotal,
            'grandTotal' => $grandTotal,
            'amountDue' => $amountDue,
            'currencyCode' => $currencyCode,
            'conversionMessage' => $conversionMessage,
            'isPerDocumentDiscount' => $isPerDocumentDiscount,
        ];
    }

    private function calculateLineSubtotalInCents(array $item, string $currencyCode): int
    {
        $quantity = max((float) ($item['quantity'] ?? 0), 0);
        $unitPrice = CurrencyConverter::isValidAmount($item['unit_price'], 'USD')
            ? CurrencyConverter::convertToFloat($item['unit_price'], 'USD')
            : 0;

        $subtotal = $quantity * $unitPrice;

        return CurrencyConverter::convertToCents($subtotal, 'USD');
    }

    private function calculateAdjustmentsTotalInCents($lineItems, string $key, string $currencyCode): int
    {
        return $lineItems->reduce(function ($carry, $item) use ($key) {
            $quantity = max((float) ($item['quantity'] ?? 0), 0);
            $unitPrice = CurrencyConverter::isValidAmount($item['unit_price'], 'USD')
                ? CurrencyConverter::convertToFloat($item['unit_price'], 'USD')
                : 0;

            $adjustmentIds = $item[$key] ?? [];
            $lineTotal = $quantity * $unitPrice;

            $lineTotalInCents = CurrencyConverter::convertToCents($lineTotal, 'USD');

            $adjustmentTotal = Adjustment::whereIn('id', $adjustmentIds)
                ->get()
                ->sum(function (Adjustment $adjustment) use ($lineTotalInCents) {
                    if ($adjustment->computation->isPercentage()) {
                        return RateCalculator::calculatePercentage($lineTotalInCents, $adjustment->getRawOriginal('rate'));
                    } else {
                        return $adjustment->getRawOriginal('rate');
                    }
                });

            return $carry + $adjustmentTotal;
        }, 0);
    }

    private function calculateDiscountTotalInCents($lineItems, int $subtotalInCents, string $currencyCode): int
    {
        $discountMethod = DocumentDiscountMethod::parse($this->data['discount_method']) ?? DocumentDiscountMethod::PerLineItem;

        if ($discountMethod->isPerLineItem()) {
            return $this->calculateAdjustmentsTotalInCents($lineItems, $this->documentType->getDiscountKey(), $currencyCode);
        }

        $discountComputation = AdjustmentComputation::parse($this->data['discount_computation']) ?? AdjustmentComputation::Percentage;
        $discountRate = blank($this->data['discount_rate']) ? '0' : $this->data['discount_rate'];

        if ($discountComputation->isPercentage()) {
            $scaledDiscountRate = RateCalculator::parseLocalizedRate($discountRate);
            return RateCalculator::calculatePercentage($subtotalInCents, $scaledDiscountRate);
        }

        if (!CurrencyConverter::isValidAmount($discountRate, $currencyCode)) {
            $discountRate = '0';
        }

        return CurrencyConverter::convertToCents($discountRate, $currencyCode);
    }

    private function calculateProfitTotalInCents(int $subtotalInCents, string $currencyCode): int
    {
        $profitComputation = AdjustmentComputation::parse($this->data['profit_computation']) ?? AdjustmentComputation::Percentage;
        $profitRate = blank($this->data['profit_rate']) ? '0' : $this->data['profit_rate'];

        if ($profitComputation->isPercentage()) {
            $scaledProfitRate = RateCalculator::parseLocalizedRate($profitRate);
            $result = RateCalculator::calculatePercentage($subtotalInCents, $scaledProfitRate);
            return $result;
        }

        if (!CurrencyConverter::isValidAmount($profitRate, $currencyCode)) {
            $profitRate = '0';
        }

        return CurrencyConverter::convertToCents($profitRate, $currencyCode);
    }

    private function buildConversionMessage(int $grandTotalInCents, string $currencyCode, string $defaultCurrencyCode): ?string
    {
        if ($currencyCode === $defaultCurrencyCode) {
            return null;
        }

        $rate = currency($currencyCode)->getRate();
        $indirectRate = 1 / $rate;

        $convertedTotalInCents = CurrencyConverter::convertBalance($grandTotalInCents, $currencyCode, $defaultCurrencyCode);

        $formattedRate = Number::format($indirectRate, maxPrecision: 10);

        return sprintf(
            'Currency conversion: %s (%s) at an exchange rate of 1 %s = %s %s',
            CurrencyConverter::formatCentsToMoney($convertedTotalInCents, $defaultCurrencyCode),
            $defaultCurrencyCode,
            $currencyCode,
            $formattedRate,
            $defaultCurrencyCode
        );
    }

    private function calculateAmountDueInCents(int $grandTotalInCents, string $currencyCode): int
    {
        $amountPaidInCents = $this->data['amount_paid'] ?? 0;
        return $grandTotalInCents - $amountPaidInCents;
    }
}
