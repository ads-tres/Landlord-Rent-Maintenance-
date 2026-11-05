<?php

namespace App\Support;

use Andegna\DateTimeFactory;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Ethiopian calendar helpers on top of andegna/calender. Dates are stored in Gregorian;
 * this is for display and for working out Ethiopian months and fiscal quarters.
 *
 * Ethiopian years have 12 months of 30 days plus Pagume (5 days, 6 in a leap year).
 * The fiscal year starts on 1 Hamle (month 11), around 8 July.
 */
final class EthiopianDate
{
    public const MONTHS_AM = [
        1 => 'መስከረም', 'ጥቅምት', 'ኅዳር', 'ታኅሣሥ', 'ጥር', 'የካቲት',
        'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜን',
    ];

    public const MONTHS_EN = [
        1 => 'Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit',
        'Megabit', 'Miyazia', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume',
    ];

    public function __construct(public readonly int $year, public readonly int $month, public readonly int $day) {}

    public static function fromGregorian(DateTimeInterface|string $date): self
    {
        $g = CarbonImmutable::parse($date)->startOfDay();
        $e = DateTimeFactory::fromDateTime(new \DateTime($g->toDateString()));

        return new self($e->getYear(), $e->getMonth(), $e->getDay());
    }

    public static function toGregorian(int $year, int $month, int $day): CarbonImmutable
    {
        return CarbonImmutable::parse(DateTimeFactory::of($year, $month, $day)->toGregorian()->format('Y-m-d'));
    }

    public static function monthName(int $month, string $locale): string
    {
        return ($locale === 'am' ? self::MONTHS_AM : self::MONTHS_EN)[$month];
    }

    public static function isLeapYear(int $year): bool
    {
        return $year % 4 === 3;
    }

    public static function daysInMonth(int $year, int $month): int
    {
        return $month < 13 ? 30 : (self::isLeapYear($year) ? 6 : 5);
    }

    public function format(string $locale): string
    {
        return $this->day.' '.self::monthName($this->month, $locale).' '.$this->year;
    }
}
