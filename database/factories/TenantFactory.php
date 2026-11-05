<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'landlord_id' => User::factory(),
            'full_name' => fake()->name(),
            'phone' => '+2519'.fake()->numerify('########'),
            'id_number' => fake()->numerify('ID-#######'),
            'address' => fake()->streetAddress(),
        ];
    }
}
