<?php

namespace App\Support;

/**
 * Money is stored as integer nano-dollars (1 USD = 1,000,000,000).
 * This class only formats; it never does arithmetic on floats.
 */
final class Money
{
    public const NANOS_PER_USD = 1_000_000_000;

    /** Format nano-dollars as "$1.50" or "$0.373875": at least 2 decimals, trailing zeros trimmed. */
    public static function format(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $abs = abs($nanos);

        $whole = intdiv($abs, self::NANOS_PER_USD);
        $fraction = str_pad((string) ($abs % self::NANOS_PER_USD), 9, '0', STR_PAD_LEFT);
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, 2, '0');

        return $sign.'$'.$whole.'.'.$fraction;
    }
}
