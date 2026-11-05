<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Property> */
class PropertyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'landlord_id' => User::factory(),
            'name' => fake()->lastName().' Compound',
            'sub_city' => fake()->randomElement(['Bole', 'Yeka', 'Kirkos', 'Arada', 'Lideta', 'Kolfe Keranio']),
            'woreda' => (string) fake()->numberBetween(1, 14),
            'address' => fake()->streetAddress(),
        ];
    }
}
