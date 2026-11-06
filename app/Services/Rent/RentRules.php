<?php

namespace App\Services\Rent;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Legal values used by the rent features. The cap changes every fiscal year, so it is
 * read from settings rather than hard-coded.
 */
class RentRules
{
    /** Addis Ababa rent-increase ceiling for 2026/27, used if the setting is missing. */
    public const DEFAULT_CAP_PERCENT = 11.5;

    /** Addis directive 7/2024: leases run at least two years. */
    public const MIN_LEASE_YEARS = 2;

    /** Days of written notice before a rent increase starts, used if the setting is missing. */
    public const DEFAULT_NOTICE_DAYS = 30;

    public function capPercent(): float
    {
        return (float) Setting::get(Setting::RENT_CAP_PERCENT, self::DEFAULT_CAP_PERCENT);
    }

    public function noticeDays(): int
    {
        return (int) Setting::get(Setting::RENT_NOTICE_DAYS, self::DEFAULT_NOTICE_DAYS);
    }

    /** First day an increase saved today may start without the tenant's written agreement. */
    public function earliestIncreaseDate(): CarbonImmutable
    {
        return CarbonImmutable::today()->addDays($this->noticeDays());
    }

    /** Percentage change from $old to $new, e.g. 10000 → 11150 is 11.5. */
    public static function increasePercent(int $old, int $new): float
    {
        return $old > 0 ? round(($new - $old) / $old * 100, 2) : 0.0;
    }

    public function exceedsCap(int $old, int $new): bool
    {
        return self::increasePercent($old, $new) > $this->capPercent();
    }

    /** Highest rent allowed after one increase from $old, in santim. */
    public function maxAllowedRent(int $old): int
    {
        return (int) floor($old * (1 + $this->capPercent() / 100));
    }
}
