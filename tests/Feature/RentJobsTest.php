<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Lease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_charges_is_safe_to_rerun(): void
    {
        $this->travelTo('2026-11-01 00:15');
        $lease = Lease::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2028-08-31']);

        $this->artisan('rent:generate-charges')->expectsOutput('Created 3 charge(s).')->assertSuccessful();
        $this->artisan('rent:generate-charges')->expectsOutput('Created 0 charge(s).')->assertSuccessful();

        $this->assertSame(3, Charge::query()->where('lease_id', $lease->id)->count());
    }

    public function test_ended_leases_get_no_new_charges(): void
    {
        $this->travelTo('2026-11-01');
        Lease::factory()->create(['status' => Lease::STATUS_ENDED, 'start_date' => '2026-09-01', 'end_date' => '2028-08-31']);

        $this->artisan('rent:generate-charges')->expectsOutput('Created 0 charge(s).');
    }

    public function test_unpaid_charges_become_late_after_the_due_date(): void
    {
        $this->travelTo('2026-11-05');
        $lease = Lease::factory()->create(['start_date' => '2026-10-05', 'end_date' => '2028-10-04', 'due_day' => 5]);
        $this->artisan('rent:generate-charges');

        // October was already past due when created, so it starts late; November is due today.
        $this->artisan('rent:mark-late')->expectsOutput('Marked 0 charge(s) late.');

        $this->assertSame('late', Charge::query()->where('period', '2026-10')->value('status'));
        $this->assertSame('due', Charge::query()->where('period', '2026-11')->value('status'));

        $this->travelTo('2026-11-06');
        $this->artisan('rent:mark-late')->expectsOutput('Marked 1 charge(s) late.');
    }

    public function test_charges_can_be_filtered_by_period_and_status(): void
    {
        $this->travelTo('2026-11-10');
        $lease = Lease::factory()->create(['start_date' => '2026-10-01', 'end_date' => '2028-09-30']);
        $this->artisan('rent:generate-charges');
        $this->artisan('rent:mark-late');

        $this->actingAsLandlord($lease->landlord);

        $this->getJson('/api/v1/charges?period=2026-11')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'late')
            ->assertJsonPath('data.0.days_late', 9)
            ->assertJsonPath('data.0.balance', 1_000_000)
            ->assertJsonPath('data.0.unit.label', $lease->unit->label);
        $this->getJson('/api/v1/charges?status=unpaid')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/charges?status=paid')->assertOk()->assertJsonCount(0, 'data');
    }
}
