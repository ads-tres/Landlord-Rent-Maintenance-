<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Setting;
use App\Models\Unit;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentChangeTest extends TestCase
{
    use RefreshDatabase;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put(Setting::RENT_CAP_PERCENT, '11.5');
        $this->travelTo('2027-07-10');
        $landlord = $this->actingAsLandlord();
        $this->lease = Lease::factory()->create([
            'landlord_id' => $landlord->id,
            'unit_id' => Unit::factory()->create(['landlord_id' => $landlord->id, 'property_id' => Property::factory()->create(['landlord_id' => $landlord->id])->id])->id,
            'start_date' => '2026-07-01',
            'end_date' => '2028-06-30',
            'monthly_rent' => 1_000_000,
        ]);
        app(RentSchedule::class)->sync($this->lease);
    }

    public function test_rules_endpoint_returns_the_current_cap(): void
    {
        $this->getJson('/api/v1/rules')
            ->assertOk()
            ->assertJsonPath('data.rent_cap_percent', 11.5)
            ->assertJsonPath('data.min_lease_years', 2);
    }

    public function test_increase_within_the_cap_is_saved_and_reprices_unpaid_charges(): void
    {
        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 1_115_000,
            'effective_date' => '2027-07-01',
        ])->assertOk()
            ->assertJsonPath('data.monthly_rent', 1_115_000)
            ->assertJsonPath('data.rent_changes.0.old_rent', 1_000_000)
            ->assertJsonPath('data.rent_changes.0.cap_percent', 11.5);

        $this->assertSame(1_000_000, Charge::query()->where('period', '2027-06')->value('amount_due'));
        $this->assertSame(1_115_000, Charge::query()->where('period', '2027-07')->value('amount_due'));

        // Charges created later use the new rent.
        $this->travelTo('2027-08-02');
        $this->artisan('rent:generate-charges')->assertSuccessful();
        $this->assertSame(1_115_000, Charge::query()->where('period', '2027-08')->value('amount_due'));
    }

    public function test_increase_above_the_cap_is_refused(): void
    {
        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 1_120_000,
            'effective_date' => '2027-07-01',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('new_rent')
            ->assertJsonPath('errors.new_rent.0', fn (string $m) => str_contains($m, 'Br 11,150.00'));

        $this->assertSame(1_000_000, $this->lease->fresh()->monthly_rent);
        $this->assertDatabaseCount('rent_changes', 0);
    }

    public function test_cap_comes_from_settings(): void
    {
        Setting::put(Setting::RENT_CAP_PERCENT, '15');

        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 1_150_000,
            'effective_date' => '2027-07-01',
        ])->assertOk();
    }

    public function test_future_rent_change_applies_from_its_month(): void
    {
        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 1_100_000,
            'effective_date' => '2027-09-01',
        ])->assertOk()
            ->assertJsonPath('data.monthly_rent', 1_000_000);

        $this->travelTo('2027-09-01 01:00');
        $this->artisan('rent:generate-charges')->assertSuccessful();

        $this->assertSame(1_000_000, Charge::query()->where('period', '2027-08')->value('amount_due'));
        $this->assertSame(1_100_000, Charge::query()->where('period', '2027-09')->value('amount_due'));
        $this->assertSame(1_100_000, $this->lease->fresh()->monthly_rent);
    }

    public function test_rent_decrease_is_allowed(): void
    {
        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 900_000,
            'effective_date' => '2027-07-01',
        ])->assertOk();
    }
}
