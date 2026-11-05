<?php

namespace Tests\Unit;

use App\Support\DateDisplay;
use App\Support\EthiopianDate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EthiopianDateTest extends TestCase
{
    public static function knownDates(): array
    {
        return [
            'new year 2019' => ['2026-09-11', 2019, 1, 1],
            'Genna' => ['2027-01-07', 2019, 4, 29],
            'Pagume 6 (leap year 2015)' => ['2023-09-11', 2015, 13, 6],
            'new year 2016' => ['2023-09-12', 2016, 1, 1],
            'fiscal year 2019 starts' => ['2026-07-08', 2018, 11, 1],
            'mid November' => ['2026-11-15', 2019, 3, 6],
        ];
    }

    #[DataProvider('knownDates')]
    public function test_converts_both_ways(string $gregorian, int $y, int $m, int $d): void
    {
        $e = EthiopianDate::fromGregorian($gregorian);
        $this->assertSame([$y, $m, $d], [$e->year, $e->month, $e->day]);
        $this->assertSame($gregorian, EthiopianDate::toGregorian($y, $m, $d)->toDateString());
    }

    public function test_formats_dates_in_both_calendars(): void
    {
        $this->assertSame('6 ኅዳር 2019', DateDisplay::date('2026-11-15', 'am', 'ethiopian'));
        $this->assertSame('6 Hidar 2019', DateDisplay::date('2026-11-15', 'en', 'ethiopian'));
        $this->assertSame('15 November 2026', DateDisplay::date('2026-11-15', 'en', 'gregorian'));
    }

    public function test_period_shows_the_ethiopian_months_it_spans(): void
    {
        $this->assertSame('ጥቅምት–ኅዳር 2019', DateDisplay::period('2026-11', 'am', 'ethiopian'));
        $this->assertSame('Nehase 2018–Meskerem 2019', DateDisplay::period('2026-09', 'en', 'ethiopian'));
        $this->assertSame('November 2026', DateDisplay::period('2026-11', 'en'));
    }

    public function test_period_range(): void
    {
        $this->assertSame('Nehase 2018–Tikimt 2019', DateDisplay::periodRange('2026-09', '2026-10', 'en', 'ethiopian'));
        $this->assertSame('September 2026 – October 2026', DateDisplay::periodRange('2026-09', '2026-10', 'en'));
        $this->assertSame('November 2026', DateDisplay::periodRange('2026-11', '2026-11', 'en'));
    }

    public function test_pagume_length(): void
    {
        $this->assertSame(6, EthiopianDate::daysInMonth(2015, 13));
        $this->assertSame(5, EthiopianDate::daysInMonth(2018, 13));
        $this->assertSame(30, EthiopianDate::daysInMonth(2018, 4));
    }
}
