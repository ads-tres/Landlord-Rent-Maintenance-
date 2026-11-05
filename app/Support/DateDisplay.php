<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Formats stored Gregorian dates in the landlord's chosen calendar and language. */
final class DateDisplay
{
    public const ETHIOPIAN = 'ethiopian';

    public const GREGORIAN = 'gregorian';

    public static function date(DateTimeInterface|string $date, string $locale, string $calendar = self::GREGORIAN): string
    {
        if ($calendar === self::ETHIOPIAN) {
            return EthiopianDate::fromGregorian($date)->format($locale);
        }

        return CarbonImmutable::parse($date)->locale($locale)->isoFormat('D MMMM YYYY');
    }

    /**
     * A rent period (Gregorian month, YYYY-MM). In the Ethiopian calendar a Gregorian
     * month spans two Ethiopian months, shown as e.g. "ጥቅምት–ኅዳር 2019".
     */
    public static function period(string $period, string $locale, string $calendar = self::GREGORIAN): string
    {
        $first = CarbonImmutable::createFromFormat('!Y-m', $period);

        if ($calendar !== self::ETHIOPIAN) {
            return $first->locale($locale)->isoFormat('MMMM YYYY');
        }

        $a = EthiopianDate::fromGregorian($first);
        $b = EthiopianDate::fromGregorian($first->endOfMonth());

        $from = EthiopianDate::monthName($a->month, $locale);
        $to = EthiopianDate::monthName($b->month, $locale);

        if ($a->year === $b->year) {
            return $a->month === $b->month ? "{$from} {$a->year}" : "{$from}–{$to} {$b->year}";
        }

        return "{$from} {$a->year}–{$to} {$b->year}";
    }

    /** Several consecutive rent periods as one short label (used in SMS). */
    public static function periodRange(string $first, string $last, string $locale, string $calendar = self::GREGORIAN): string
    {
        if ($first === $last) {
            return self::period($first, $locale, $calendar);
        }

        $start = CarbonImmutable::createFromFormat('!Y-m', $first);
        $end = CarbonImmutable::createFromFormat('!Y-m', $last)->endOfMonth();

        if ($calendar !== self::ETHIOPIAN) {
            return $start->locale($locale)->isoFormat('MMMM YYYY').' – '.$end->locale($locale)->isoFormat('MMMM YYYY');
        }

        $a = EthiopianDate::fromGregorian($start);
        $b = EthiopianDate::fromGregorian($end);
        $from = EthiopianDate::monthName($a->month, $locale);
        $to = EthiopianDate::monthName($b->month, $locale);

        return $a->year === $b->year ? "{$from}–{$to} {$b->year}" : "{$from} {$a->year}–{$to} {$b->year}";
    }
}
