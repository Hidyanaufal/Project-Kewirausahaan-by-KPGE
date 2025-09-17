<?php

namespace App\Concerns;

use Illuminate\Support\Str;

trait HasCode
{
    /**
     * Generate a unique random code for the given column.
     */
    public static function makeCode(
        string $column = 'code',
        int $length = 6
    ): string {
        do {
            $code = strtoupper(Str::random($length));
        } while (self::where($column, $code)->exists());

        return $code;
    }
}
