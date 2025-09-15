<?php

namespace App\Filament\Pages\Auth;

use DiogoGPinto\AuthUIEnhancer\Pages\Auth\Concerns\HasCustomLayout;
use Wallo\FilamentCompanies\Pages\Auth\Login as BaseLogin;

class Login extends BaseLogin
{
    use HasCustomLayout;

    public function mount(): void
    {
        parent::mount();

        if (is_demo_environment()) {
            $this->form->fill([
                'email' => 'admin@erpsaas.com',
                'password' => 'password',
                'remember' => true,
            ]);
        }
    }
}
