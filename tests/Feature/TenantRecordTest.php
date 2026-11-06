<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Lease;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-20 10:00');
        $this->landlord = $this->actingAsLandlord();
        $this->landlord->update(['calendar_pref' => 'gregorian']);
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['landlord_id' => $this->landlord->id, 'property_id' => $property->id]);
        $tenant = Tenant::factory()->create(['landlord_id' => $this->landlord->id, 'phone' => '+251911223344', 'full_name' => 'Almaz']);
        $this->lease = Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'due_day' => 5, 'monthly_rent' => 1_000_000,
        ]);
        app(RentSchedule::class)->sync($this->lease);

        // January paid early, February 5 days late, March 10 days late, April 29 days late, May still owed.
        foreach (['2026-01-03', '2026-02-10', '2026-03-15', '2026-05-04'] as $date) {
            $this->postJson('/api/v1/payments', [
                'lease_id' => $this->lease->id, 'amount' => 1_000_000, 'paid_at' => $date, 'method' => 'cash', 'send_sms' => false,
            ])->assertCreated();
        }
    }

    public function test_record_counts_on_time_late_and_unpaid_months(): void
    {
        $data = $this->getJson("/api/v1/tenants/{$this->lease->tenant_id}/record")->assertOk()->json('data');

        $this->assertSame([5, 1, 3, 1], [$data['charges'], $data['on_time'], $data['late'], $data['unpaid']]);
        $this->assertEquals(20, $data['on_time_percent']);
        $this->assertSame(14.7, $data['average_days_late']);
        $this->assertSame(1_000_000, $data['outstanding']);
        $this->assertSame('poor', $data['rating']);
        $this->assertSame('2026-01-01', $data['tenant_since']);
        $this->assertSame(['on_time', 'late', 'late', 'late', 'unpaid'], array_column($data['recent'], 'status'));
        $this->assertSame([null, 5, 10, 29, 15], array_column($data['recent'], 'days_late'));
    }

    public function test_landlord_keeps_private_notes(): void
    {
        $tenant = $this->lease->tenant_id;
        $this->putJson("/api/v1/tenants/{$tenant}/notes", ['notes' => '  Always calls before paying late. '])
            ->assertOk()->assertJsonPath('data.notes', 'Always calls before paying late.');

        $this->getJson("/api/v1/tenants/{$tenant}/record")->assertJsonPath('data.notes', 'Always calls before paying late.');
        // The tenant sees them if they ask for their data.
        $this->getJson("/api/v1/tenants/{$tenant}/export")->assertJsonPath('tenant.landlord_notes', 'Always calls before paying late.');

        $this->putJson("/api/v1/tenants/{$tenant}/notes", ['notes' => ''])->assertOk()->assertJsonPath('data.notes', null);
    }

    public function test_record_shows_at_renewal_and_when_a_former_tenant_applies(): void
    {
        $this->getJson("/api/v1/leases/{$this->lease->id}/renewal-check")
            ->assertOk()
            ->assertJsonPath('data.tenant_record.rating', 'poor')
            ->assertJsonPath('data.tenant_record.charges', 5);

        $listing = new Listing(['unit_id' => $this->lease->unit_id, 'asking_rent' => 1_100_000, 'available_from' => '2026-06-01', 'status' => Listing::STATUS_LIVE]);
        $listing->landlord_id = $this->landlord->id;
        $listing->save();
        foreach (['+251911223344' => 'Almaz T.', '+251922000000' => 'Someone new'] as $phone => $name) {
            $application = new Application(['listing_id' => $listing->id, 'full_name' => $name, 'phone' => $phone, 'phone_verified_at' => now(), 'household_size' => 2, 'move_in_date' => '2026-06-01', 'status' => 'new']);
            $application->landlord_id = $this->landlord->id;
            $application->save();
        }

        $apps = collect($this->getJson("/api/v1/listings/{$listing->id}/applications")->assertOk()->json('data'))->keyBy('phone');
        $this->assertSame('Almaz', $apps['+251911223344']['former_tenant']['full_name']);
        $this->assertSame('poor', $apps['+251911223344']['former_tenant']['rating']);
        $this->assertNull($apps['+251922000000']['former_tenant']);
    }

    public function test_another_landlords_tenant_is_hidden(): void
    {
        $this->actingAsLandlord();
        $this->getJson("/api/v1/tenants/{$this->lease->tenant_id}/record")->assertNotFound();
        $this->putJson("/api/v1/tenants/{$this->lease->tenant_id}/notes", ['notes' => 'x'])->assertNotFound();
    }
}
