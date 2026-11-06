<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\Property;
use App\Models\RentChange;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnitHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Unit $let;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-31 12:00');
        $this->landlord = $this->actingAsLandlord();
        $this->landlord->update(['calendar_pref' => 'gregorian']);
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id, 'name' => 'Bole House']);

        // Added 1 Jan; empty until March, let Mar–May, empty in June, let again from July.
        $this->let = $this->unit($property, 'Unit 1', '2026-01-01');
        Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $this->let->id,
            'start_date' => '2026-03-01', 'end_date' => '2028-02-29', 'terminated_on' => '2026-05-31',
            'status' => Lease::STATUS_ENDED, 'monthly_rent' => 1_000_000,
        ]);
        $current = Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $this->let->id,
            'start_date' => '2026-07-01', 'end_date' => '2028-06-30', 'monthly_rent' => 1_300_000,
        ]);
        $change = new RentChange(['lease_id' => $current->id, 'old_rent' => 1_200_000, 'new_rent' => 1_300_000, 'effective_date' => '2026-10-01', 'cap_percent' => 11.5]);
        $change->landlord_id = $this->landlord->id;
        $change->save();
        $repair = new MaintenanceRequest(['unit_id' => $this->let->id, 'title' => 'Broken window', 'category' => 'other', 'status' => 'done', 'source' => 'landlord', 'opened_at' => '2026-04-10', 'cost' => 50_000]);
        $repair->landlord_id = $this->landlord->id;
        $repair->save();
        $this->let->update(['status' => Unit::STATUS_OCCUPIED]);

        // Added in June, never let, listed at Br 8,000.
        $listed = $this->unit($property, 'Unit 2', '2026-06-01');
        $listing = new Listing(['unit_id' => $listed->id, 'asking_rent' => 800_000, 'available_from' => '2026-06-01', 'status' => Listing::STATUS_LIVE]);
        $listing->landlord_id = $this->landlord->id;
        $listing->save();

        // Never let or listed: empty, but no rent to go by.
        $this->unit($property, 'Unit 3', '2026-11-01');
    }

    private function unit(Property $property, string $label, string $added): Unit
    {
        $unit = Unit::factory()->create(['landlord_id' => $this->landlord->id, 'property_id' => $property->id, 'label' => $label]);
        $unit->forceFill(['created_at' => $added])->save();

        return $unit;
    }

    public function test_unit_history_lists_tenancies_gaps_rent_changes_and_repairs(): void
    {
        $data = $this->getJson("/api/v1/units/{$this->let->id}/history")->assertOk()->json('data');

        $this->assertSame(['rent_changed', 'lease_started', 'vacant', 'lease_ended', 'repair', 'lease_started', 'vacant'], array_column($data['events'], 'type'));

        // June: 30 days at the next lease's starting rent (Br 12,000, before the October rise).
        $june = $data['events'][2];
        $this->assertSame(['2026-06-01', '2026-06-30', 30, 1_200_000], [$june['date'], $june['until'], $june['days'], $june['lost']]);
        // Jan–Feb: 59 days at Br 10,000 a month.
        $this->assertSame(1_966_667, $data['events'][6]['lost']);
        $this->assertSame(1_200_000, $data['events'][1]['rent']);
        $this->assertTrue($data['events'][3]['early']);

        $this->assertSame(89, $data['year']['vacant_days']);
        $this->assertSame(75.6, $data['year']['occupancy_percent']);
        $this->assertSame(3_166_667, $data['year']['lost']);
        $this->assertSame(50_000, $data['year']['repair_cost']);
        $this->assertSame(2, $data['totals']['tenants']);
        $this->assertEquals(3, $data['totals']['average_stay_months']);
        $this->assertSame(1_300_000, $data['totals']['current_rent']);
    }

    public function test_vacancy_report_ranks_units_by_rent_lost(): void
    {
        $data = $this->getJson('/api/v1/vacancy')->assertOk()->json('data');

        $this->assertSame(2026, $data['year']);
        $this->assertSame(['Unit 2', 'Unit 1', 'Unit 3'], array_column($data['units'], 'label'));
        $this->assertSame(5_706_667, $data['units'][0]['lost']);   // 214 days at Br 8,000
        $this->assertSame(8_873_334, $data['lost']);
        $this->assertSame(1, $data['unpriced_units']);

        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.vacancy_lost_this_year', 8_873_334);
    }

    public function test_another_landlords_unit_is_not_visible(): void
    {
        $this->actingAsLandlord();
        $this->getJson("/api/v1/units/{$this->let->id}/history")->assertNotFound();
    }
}
