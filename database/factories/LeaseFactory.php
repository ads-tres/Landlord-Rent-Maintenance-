<?php

namespace Database\Factories;

use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lease> */
class LeaseFactory extends Factory
{
    public function definition(): array
    {
        $start = today()->startOfMonth();

        return [
            'unit_id' => Unit::factory(),
            'landlord_id' => fn (array $a) => Unit::withoutGlobalScopes()->find($a['unit_id'])->landlord_id,
            'tenant_id' => fn (array $a) => Tenant::factory()->create(['landlord_id' => $a['landlord_id']])->id,
            'start_date' => $start,
            'end_date' => $start->copy()->addYears(2)->subDay(),
            'monthly_rent' => 1_000_000, // Br 10,000
            'deposit' => 2_000_000,
            'due_day' => 1,
            'due_calendar' => 'gregorian',
            'condition_notes' => 'Freshly painted, all fixtures working.',
            'status' => Lease::STATUS_ACTIVE,
        ];
    }
}
