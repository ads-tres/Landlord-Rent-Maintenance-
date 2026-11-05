<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Unit> */
class UnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'landlord_id' => fn (array $attrs) => Property::withoutGlobalScopes()->find($attrs['property_id'])->landlord_id,
            'label' => 'Room '.fake()->unique()->numberBetween(1, 999),
            'bedrooms' => fake()->numberBetween(0, 3),
            'status' => Unit::STATUS_VACANT,
        ];
    }
}
