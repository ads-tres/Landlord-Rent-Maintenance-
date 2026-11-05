<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One landlord must never be able to read or change another landlord's data. */
class LandlordIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_landlord_cannot_see_or_touch_another_landlords_records(): void
    {
        $other = User::factory()->create();
        $property = Property::factory()->for($other, 'landlord')->create();
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $other->id]);
        $tenant = Tenant::factory()->for($other, 'landlord')->create();

        $this->actingAsLandlord();

        $this->getJson('/api/v1/properties')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/tenants')->assertOk()->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/properties/{$property->id}")->assertNotFound();
        $this->patchJson("/api/v1/properties/{$property->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->deleteJson("/api/v1/properties/{$property->id}")->assertNotFound();

        $this->getJson("/api/v1/properties/{$property->id}/units")->assertNotFound();
        $this->postJson("/api/v1/properties/{$property->id}/units", ['label' => 'X'])->assertNotFound();
        $this->getJson("/api/v1/units/{$unit->id}")->assertNotFound();
        $this->deleteJson("/api/v1/units/{$unit->id}")->assertNotFound();

        $this->getJson("/api/v1/tenants/{$tenant->id}")->assertNotFound();
        $this->deleteJson("/api/v1/tenants/{$tenant->id}")->assertNotFound();

        $this->assertDatabaseHas('properties', ['id' => $property->id, 'name' => $property->name]);
        $this->assertDatabaseHas('units', ['id' => $unit->id]);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    public function test_new_records_are_stamped_with_the_current_landlord(): void
    {
        $landlord = $this->actingAsLandlord();

        $id = $this->postJson('/api/v1/properties', ['name' => 'Mine', 'landlord_id' => 999])->json('data.id');

        $this->assertSame($landlord->id, Property::query()->find($id)->landlord_id);
    }
}
