<?php

namespace App\Filament\User\Clusters\Account\Pages;

use App\Filament\User\Clusters\Account;
use Filament\Support\Enums\MaxWidth;
use Wallo\FilamentCompanies\Pages\User\Profile as BaseProfile;

class Profile extends BaseProfile
{
    protected static string $view = 'filament-companies::filament.pages.user.profile';
    
    protected static ?string $cluster = Account::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 10;

    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return MaxWidth::ScreenTwoExtraLarge;
    }
}
