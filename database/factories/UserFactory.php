<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'phone' => '+2519'.fake()->unique()->numerify('########'),
            'name' => fake()->name(),
            'locale' => 'en',
            'calendar_pref' => 'ethiopian',
            'role' => User::ROLE_LANDLORD,
            'phone_verified_at' => now(),
        ];
    }
}
