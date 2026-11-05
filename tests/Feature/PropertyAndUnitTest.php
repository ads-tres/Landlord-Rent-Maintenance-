<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyAndUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_landlord_can_create_and_list_properties(): void
    {
        $landlord = $this->actingAsLandlord();

        $this->postJson('/api/v1/properties', ['name' => 'Bole Compound', 'sub_city' => 'Bole', 'woreda' => '3'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Bole Compound')
            ->assertJsonPath('data.units_count', 0);

        $this->assertDatabaseHas('properties', ['name' => 'Bole Compound', 'landlord_id' => $landlord->id]);

        $this->getJson('/api/v1/properties')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_property_name_is_required(): void
    {
        $this->actingAsLandlord();

        $this->postJson('/api/v1/properties', [])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_landlord_can_add_units_to_a_property(): void
    {
        $landlord = $this->actingAsLandlord();
        $property = Property::factory()->for($landlord, 'landlord')->create();

        $this->postJson("/api/v1/properties/{$property->id}/units", ['label' => 'Room 1', 'bedrooms' => 1])
            ->assertCreated()
            ->assertJsonPath('data.status', 'vacant');

        // Labels are unique within a property.
        $this->postJson("/api/v1/properties/{$property->id}/units", ['label' => 'Room 1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label');

        $this->getJson("/api/v1/properties/{$property->id}/units")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/properties/{$property->id}")
            ->assertOk()
            ->assertJsonPath('data.units_count', 1)
            ->assertJsonPath('data.vacant_units_count', 1);
    }

    public function test_landlord_can_update_and_delete_a_unit(): void
    {
        $landlord = $this->actingAsLandlord();
        $property = Property::factory()->for($landlord, 'landlord')->create();
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $landlord->id]);

        $this->patchJson("/api/v1/units/{$unit->id}", ['status' => 'occupied'])
            ->assertOk()
            ->assertJsonPath('data.status', 'occupied');

        // A property with occupied units cannot be deleted.
        $this->deleteJson("/api/v1/properties/{$property->id}")->assertConflict();

        $this->deleteJson("/api/v1/units/{$unit->id}")->assertNoContent();
        $this->deleteJson("/api/v1/properties/{$property->id}")->assertNoContent();
    }
}
