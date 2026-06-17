<?php

namespace App\Enums\Accounting;

use Filament\Support\Contracts\HasLabel;

enum AccountDefaultKey: string implements HasLabel
{
    case AccountsReceivable = 'accounts_receivable';
    case AccountsPayable    = 'accounts_payable';
    case SalesDiscount      = 'sales_discount';
    case PurchaseDiscount   = 'purchase_discount';
    case PPn                = 'ppn';
    case PPh23              = 'pph_23';
    case PPh23Payable       = 'pph_23_payable';
    case PPnWapu            = 'ppn_wapu';
    case Profit             = 'profit';
    case OpenBalance        = 'open_balance';
    case CurrentYearEarnings = 'current_year_earnings';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::AccountsReceivable => 'Accounts Receivable',
            self::AccountsPayable    => 'Accounts Payable',
            self::SalesDiscount      => 'Sales Discount',
            self::PurchaseDiscount   => 'Purchase Discount',
            self::PPh23              => 'PPh 23',
            self::PPh23Payable       => 'Hutang PPh 23',
            self::PPn                => 'PPN',
            self::PPnWapu            => 'PPN WAPU',
            self::Profit             => 'Profit',
            self::OpenBalance        => 'Open Balance Equity',
            self::CurrentYearEarnings => 'Laba Tahun Berjalan',
        };
    }
}
