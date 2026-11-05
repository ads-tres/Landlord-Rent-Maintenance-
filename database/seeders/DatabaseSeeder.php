<?php

namespace Database\Seeders;

use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SettingsSeeder::class);

        if (app()->environment('local')) {
            $landlord = User::query()->firstOrCreate(
                ['phone' => '+251911000000'],
                ['name' => 'Demo Landlord', 'locale' => 'am', 'phone_verified_at' => now()],
            );

            if (! Property::withoutGlobalScopes()->where('landlord_id', $landlord->id)->exists()) {
                $property = Property::factory()->for($landlord, 'landlord')->create(['name' => 'Bole Compound']);
                foreach (['Room 1', 'Room 2', 'Room 3'] as $label) {
                    Unit::factory()->for($property)->create(['label' => $label, 'landlord_id' => $landlord->id]);
                }
                Tenant::factory()->count(2)->for($landlord, 'landlord')->create();
            }
        }
    }
}
