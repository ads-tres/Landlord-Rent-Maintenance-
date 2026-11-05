<?php

namespace Tests\Unit;

use App\Models\Lease;
use App\Models\RentChange;
use App\Services\Rent\RentRules;
use App\Services\Rent\RentSchedule;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class RentScheduleTest extends TestCase
{
    private function lease(string $start, string $end, int $dueDay = 1, ?string $terminated = null): Lease
    {
        $lease = new Lease;
        $lease->forceFill([
            'start_date' => $start,
            'end_date' => $end,
            'terminated_on' => $terminated,
            'due_day' => $dueDay,
            'monthly_rent' => 1_000_000,
        ]);
        $lease->setRelation('rentChanges', collect());

        return $lease;
    }

    public function test_two_year_lease_has_24_monthly_periods(): void
    {
        $periods = (new RentSchedule)->periods($this->lease('2026-11-05', '2028-11-04', 5));

        $this->assertCount(24, $periods);
        $this->assertSame('2026-11', $periods[0]);
        $this->assertSame('2028-10', $periods[23]);
    }

    public function test_lease_starting_on_the_first_has_24_periods(): void
    {
        $periods = (new RentSchedule)->periods($this->lease('2026-07-01', '2028-06-30'));

        $this->assertCount(24, $periods);
        $this->assertSame('2028-06', $periods[23]);
    }

    public function test_part_month_at_the_end_counts_as_a_period(): void
    {
        $periods = (new RentSchedule)->periods($this->lease('2026-07-01', '2028-07-10'));

        $this->assertCount(25, $periods);
    }

    public function test_early_termination_shortens_the_schedule(): void
    {
        $periods = (new RentSchedule)->periods($this->lease('2026-07-01', '2028-06-30', 1, '2026-12-31'));

        $this->assertSame(['2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12'], $periods);
    }

    public function test_due_day_is_clamped_to_the_month_and_never_before_the_start(): void
    {
        $schedule = new RentSchedule;
        $lease = $this->lease('2026-11-20', '2028-11-19', 30);

        $this->assertSame('2026-11-30', $schedule->dueDate($lease, '2026-11')->toDateString());
        $this->assertSame('2027-02-28', $schedule->dueDate($lease, '2027-02')->toDateString());
        $this->assertSame('2028-02-29', $schedule->dueDate($lease, '2028-02')->toDateString());

        $early = $this->lease('2026-11-20', '2028-11-19', 5);
        $this->assertSame('2026-11-20', $schedule->dueDate($early, '2026-11')->toDateString());
        $this->assertSame('2026-12-05', $schedule->dueDate($early, '2026-12')->toDateString());
    }

    public function test_rent_for_period_follows_rent_changes(): void
    {
        $lease = $this->lease('2026-07-01', '2028-06-30');
        $change = new RentChange;
        $change->forceFill(['old_rent' => 1_000_000, 'new_rent' => 1_100_000, 'effective_date' => CarbonImmutable::parse('2027-07-01')]);
        $lease->monthly_rent = 1_100_000;
        $lease->setRelation('rentChanges', collect([$change]));

        $schedule = new RentSchedule;
        $this->assertSame(1_000_000, $schedule->rentFor($lease, '2027-06'));
        $this->assertSame(1_100_000, $schedule->rentFor($lease, '2027-07'));
        $this->assertSame(1_100_000, $schedule->rentFor($lease, '2028-01'));
    }

    public function test_increase_percent(): void
    {
        $this->assertSame(11.5, RentRules::increasePercent(1_000_000, 1_115_000));
        $this->assertSame(-10.0, RentRules::increasePercent(1_000_000, 900_000));
    }
}
