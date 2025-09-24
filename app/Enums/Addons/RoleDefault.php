<?php

namespace App\Enums\Addons;

use Filament\Support\Contracts\HasLabel;

enum RoleDefault: string implements HasLabel
{
    case Admin = 'Administrator';
    case User = 'User';

    public function getLabel(): ?string
    {
        return $this->name;
    }
}
