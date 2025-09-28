<?php

namespace App\Enums\Setting;

use App\Enums\Concerns\ParsesEnum;
use Filament\Support\Contracts\HasLabel;

enum Template: string implements HasLabel
{
    use ParsesEnum;

    case Default = 'default';
    case Classic = 'classic';
    case Modern = 'modern';
    case CustomInvoice = 'custom.invoice';
    case CustomQuotation = 'custom.quotation';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Default => 'Default',
            self::Classic => 'Classic',
            self::Modern => 'Modern',
            self::CustomInvoice => '[Custom] Invoice',
            self::CustomQuotation => '[Custom] Quotation',
        };
    }
}
