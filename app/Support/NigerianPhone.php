<?php

namespace App\Support;

use InvalidArgumentException;

/** Reduces a Nigerian mobile number to its 10 local digits: 0806 234 5678 -> 8062345678. */
final class NigerianPhone
{
    public static function normalise(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        $local = preg_replace('/^(234|0)/', '', $digits);

        if (! preg_match('/^[789][01]\d{8}$/', $local)) {
            throw new InvalidArgumentException('Not a valid Nigerian mobile number.');
        }

        return $local;
    }
}
