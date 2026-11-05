<?php

namespace App\Support;

/**
 * Normalises Ethiopian mobile numbers to E.164 (+2519XXXXXXXX / +2517XXXXXXXX).
 * Accepts 09..., 07..., 9..., 2519..., +2519... with spaces or dashes.
 */
final class PhoneNumber
{
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if (str_starts_with($digits, '251')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (! preg_match('/^[79]\d{8}$/', $digits)) {
            return null;
        }

        return '+251'.$digits;
    }
}
