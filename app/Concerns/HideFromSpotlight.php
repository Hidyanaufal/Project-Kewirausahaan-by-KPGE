<?php

namespace App\Concerns;

trait HideFromSpotlight
{
    public static function shouldRegisterSpotlight(): bool
    {
        return false;
    }
}
