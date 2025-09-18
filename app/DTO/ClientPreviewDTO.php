<?php

namespace App\DTO;

readonly class ClientPreviewDTO extends ClientDTO
{
    public static function fake(): self
    {
        return new self(
            name: 'PT. PERTAMINA GEOTHERMAL ENERGY TBK',
            addressLine1: 'Kantor Pusat - Grha Pertamina Tower Pertamax Lt. 7',
            addressLine2: 'Jalan Merdeka Timur No. 11-13,',
            city: 'Gambir',
            state: 'Jakarta Pusat',
            postalCode: '10110',
            country: 'Indonesia',
        );
    }
}
