<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaseTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Unit $unit;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00:00');
        $this->landlord = $this->actingAsLandlord(User::factory()->create(['name' => 'Abel']));
        $property = Property::factory()->for($this->landlord, 'landlord')->create();
        $this->unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id]);
        $this->tenant = Tenant::factory()->for($this->landlord, 'landlord')->create(['address' => 'Bole, Woreda 3']);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'unit_id' => $this->unit->id,
            'tenant_id' => $this->tenant->id,
            'start_date' => '2026-09-05',
            'end_date' => '2028-09-04',
            'monthly_rent' => 1_200_000,
            'deposit' => 2_400_000,
            'condition_notes' => 'Good condition, new kitchen tiles.',
            ...$overrides,
        ];
    }

    public function test_creating_a_lease_occupies_the_unit_and_generates_charges_to_date(): void
    {
        $response = $this->postJson('/api/v1/leases', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.due_day', 5) // defaults to the start day
            ->assertJsonPath('data.tenant.full_name', $this->tenant->full_name)
            ->assertJsonPath('data.directive_check.complete', true)
            ->assertJsonPath('data.balance.charged', 3 * 1_200_000);

        $this->assertSame('occupied', $this->unit->fresh()->status);

        // September, October and November 2026; December is created by the daily job.
        $this->getJson('/api/v1/charges?lease_id='.$response->json('data.id'))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.period', '2026-09')
            ->assertJsonPath('data.0.due_date', '2026-09-05')
            ->assertJsonPath('data.2.period', '2026-11');
    }

    public function test_lease_must_run_at_least_two_years(): void
    {
        $this->postJson('/api/v1/leases', $this->payload(['end_date' => '2027-09-04']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');

        $this->assertSame(0, Lease::query()->count());
    }

    public function test_condition_notes_are_required_by_the_directive(): void
    {
        $this->postJson('/api/v1/leases', $this->payload(['condition_notes' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('condition_notes');
    }

    public function test_an_occupied_unit_cannot_get_a_second_lease(): void
    {
        $this->postJson('/api/v1/leases', $this->payload())->assertCreated();

        $other = Tenant::factory()->for($this->landlord, 'landlord')->create();
        $this->postJson('/api/v1/leases', $this->payload(['tenant_id' => $other->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit_id');
    }

    public function test_cannot_lease_another_landlords_unit_or_tenant(): void
    {
        $otherUnit = Unit::factory()->create();
        $otherTenant = Tenant::factory()->create();

        $this->postJson('/api/v1/leases', $this->payload(['unit_id' => $otherUnit->id, 'tenant_id' => $otherTenant->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_id', 'tenant_id']);
    }

    public function test_directive_check_flags_missing_fields(): void
    {
        $this->tenant->update(['address' => null]);
        $this->landlord->update(['name' => null]);

        $this->postJson('/api/v1/leases', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.directive_check.complete', false)
            ->assertJsonPath('data.directive_check.missing', ['landlord_name', 'tenant_address']);
    }

    public function test_leases_ending_soon_can_be_listed(): void
    {
        Lease::factory()->create([
            'unit_id' => $this->unit->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'start_date' => '2024-12-01',
            'end_date' => '2026-12-31',
        ]);
        Lease::factory()->create(['landlord_id' => $this->landlord->id, 'unit_id' => Unit::factory()->create(['landlord_id' => $this->landlord->id, 'property_id' => $this->unit->property_id])->id]);

        $this->getJson('/api/v1/leases?ending_within_days=60')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.days_until_end', 46);
    }

    public function test_ending_a_lease_frees_the_unit_and_drops_future_unpaid_charges(): void
    {
        $this->travelTo('2026-09-10');
        $id = $this->postJson('/api/v1/leases', $this->payload())->json('data.id');
        $this->travelTo('2026-11-15');
        $this->artisan('rent:generate-charges')->assertSuccessful();

        $this->postJson("/api/v1/leases/{$id}/end", ['ended_on' => '2026-10-31'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.terminated_on', '2026-10-31');

        $this->assertSame('vacant', $this->unit->fresh()->status);
        $this->getJson("/api/v1/charges?lease_id={$id}")->assertJsonCount(2, 'data');

        // A unit freed this way can be leased again.
        $this->postJson('/api/v1/leases', $this->payload(['start_date' => '2026-11-01', 'end_date' => '2028-10-31']))
            ->assertCreated();
    }

    public function test_tenant_with_an_active_lease_cannot_be_deleted(): void
    {
        $this->postJson('/api/v1/leases', $this->payload())->assertCreated();

        $this->deleteJson("/api/v1/tenants/{$this->tenant->id}")->assertConflict();
        $this->deleteJson("/api/v1/units/{$this->unit->id}")->assertConflict();
    }

    public function test_another_landlord_cannot_see_the_lease(): void
    {
        $id = $this->postJson('/api/v1/leases', $this->payload())->json('data.id');

        $this->actingAsLandlord();

        $this->getJson("/api/v1/leases/{$id}")->assertNotFound();
        $this->postJson("/api/v1/leases/{$id}/end", ['ended_on' => '2026-11-30'])->assertNotFound();
        $this->getJson('/api/v1/charges')->assertOk()->assertJsonCount(0, 'data');
    }
}
