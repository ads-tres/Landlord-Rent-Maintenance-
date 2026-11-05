<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payments\PaymentRecorder;
use App\Services\Rent\RentSchedule;
use App\Services\Reports\QuarterlyReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuarterlyReportTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2027-01-20 10:00');
        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian']);
        $property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);

        foreach (['Room 1' => 1_000_000, 'Room 2' => 1_500_000] as $label => $rent) {
            $unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => $label, 'status' => 'occupied']);
            $lease = Lease::factory()->create([
                'landlord_id' => $this->landlord->id, 'unit_id' => $unit->id,
                'start_date' => '2026-07-01', 'end_date' => '2028-06-30', 'monthly_rent' => $rent,
            ]);
            app(RentSchedule::class)->sync($lease);

            // One payment a month, July 2026 to January 2027, on the 5th.
            foreach (['2026-07-05', '2026-08-05', '2026-09-05', '2026-10-05', '2026-11-05', '2026-12-05', '2027-01-05'] as $i => $date) {
                app(PaymentRecorder::class)->record($lease, [
                    'amount' => $rent, 'paid_at' => $date, 'method' => $i % 2 ? 'cash' : 'telebirr', 'reference' => "{$label}-{$i}",
                ]);
            }
        }
    }

    public function test_quarter_ranges_in_both_calendars(): void
    {
        [$from, $to] = QuarterlyReport::range(2019, 1, 'ethiopian');
        $this->assertSame(['2026-07-08', '2026-10-10'], [$from->toDateString(), $to->toDateString()]);

        [$from, $to] = QuarterlyReport::range(2019, 2, 'ethiopian');
        $this->assertSame(['2026-10-11', '2027-01-08'], [$from->toDateString(), $to->toDateString()]);

        [$from, $to] = QuarterlyReport::range(2026, 4, 'gregorian');
        $this->assertSame(['2026-10-01', '2026-12-31'], [$from->toDateString(), $to->toDateString()]);

        $this->assertSame(['year' => 2019, 'quarter' => 1], QuarterlyReport::quarterOf(CarbonImmutable::parse('2026-07-08'), 'ethiopian'));
        $this->assertSame(['year' => 2018, 'quarter' => 4], QuarterlyReport::quarterOf(CarbonImmutable::parse('2026-07-07'), 'ethiopian'));
        $this->assertSame(['year' => 2019, 'quarter' => 2], QuarterlyReport::quarterOf(CarbonImmutable::parse('2027-01-08'), 'ethiopian'));
        $this->assertSame(['year' => 2019, 'quarter' => 3], QuarterlyReport::quarterOf(CarbonImmutable::parse('2027-01-09'), 'ethiopian'));
    }

    public function test_gregorian_quarter_totals(): void
    {
        $this->actingAsLandlord($this->landlord);

        $this->getJson('/api/v1/reports/quarterly?year=2026&quarter=4&calendar=gregorian')
            ->assertOk()
            ->assertJsonPath('data.label', 'Q4 2026')
            ->assertJsonPath('data.total', 3 * 2_500_000)
            ->assertJsonPath('data.payments_count', 6)
            ->assertJsonPath('data.by_month.0.label', 'October 2026')
            ->assertJsonCount(3, 'data.by_month')
            ->assertJsonPath('data.by_property.0.property', 'Bole Compound')
            ->assertJsonPath('data.by_property.0.units.1.unit', 'Room 2')
            ->assertJsonPath('data.by_property.0.units.1.total', 4_500_000);
    }

    public function test_ethiopian_fiscal_quarter_totals(): void
    {
        $this->actingAsLandlord($this->landlord);

        // Q1 of fiscal 2019: 8 July – 10 October 2026 → Aug, Sep, Oct 5th payments (July 5 is Q4 of 2018).
        $this->getJson('/api/v1/reports/quarterly?year=2019&quarter=1&calendar=ethiopian', ['Accept-Language' => 'am'])
            ->assertOk()
            ->assertJsonPath('data.total', 3 * 2_500_000)
            ->assertJsonPath('data.from_display', '1 ሐምሌ 2018')
            ->assertJsonPath('data.by_month.0.label', 'ሐምሌ 2018'); // 5 Aug 2026 = 29 Hamle 2018
    }

    public function test_defaults_to_the_current_quarter_in_the_landlords_calendar(): void
    {
        $this->landlord->update(['calendar_pref' => 'ethiopian']);
        $this->actingAsLandlord($this->landlord);

        // 20 Jan 2027 = 12 Tir 2019 → Q3 of fiscal 2019.
        $this->getJson('/api/v1/reports/quarterly')
            ->assertOk()
            ->assertJsonPath('data.year', 2019)
            ->assertJsonPath('data.quarter', 3)
            ->assertJsonPath('data.label', 'Q3, fiscal year 2019')
            ->assertJsonPath('data.total', 0); // Q3 starts 1 Tir (9 Jan); nothing paid since

        // 5 Nov, 5 Dec and 5 Jan all fall in Q2 (11 Oct – 8 Jan).
        $this->getJson('/api/v1/reports/quarterly?year=2019&quarter=2')
            ->assertJsonPath('data.total', 3 * 2_500_000);
    }

    public function test_report_pdf(): void
    {
        $this->actingAsLandlord($this->landlord);

        $response = $this->get('/api/v1/reports/quarterly?year=2026&quarter=4&format=pdf', ['Accept-Language' => 'am'])->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('rent-income-2026-Q4.pdf', $response->headers->get('content-disposition'));
    }

    public function test_reports_only_include_the_landlords_own_payments(): void
    {
        $this->actingAsLandlord();

        $this->getJson('/api/v1/reports/quarterly?year=2026&quarter=4&calendar=gregorian')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.by_month', []);
    }
}
