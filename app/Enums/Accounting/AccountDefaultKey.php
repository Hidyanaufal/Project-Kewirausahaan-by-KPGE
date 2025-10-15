<?php

namespace App\Enums\Accounting;

use Filament\Support\Contracts\HasLabel;

enum AccountDefaultKey: string implements HasLabel
{
    case AccountsReceivable = 'accounts_receivable';
    case AccountsPayable    = 'accounts_payable';
    case SalesDiscount      = 'sales_discount';
    case PurchaseDiscount   = 'purchase_discount';
    case PPh23              = 'pph_23';
    case PPnWapu            = 'ppn_wapu';
    case Profit             = 'profit';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::AccountsReceivable => 'Accounts Receivable',
            self::AccountsPayable    => 'Accounts Payable',
            self::SalesDiscount      => 'Sales Discount',
            self::PurchaseDiscount   => 'Purchase Discount',
            self::PPh23              => 'PPh 23',
            self::PPnWapu            => 'PPN WAPU',
            self::Profit             => 'Profit',
        };
    }
}
