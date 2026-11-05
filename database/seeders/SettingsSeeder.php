<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/** Legal values that change yearly. Update them here or in the database, not in code. */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        // Addis Ababa rent-increase ceiling for the 2026/27 fiscal year (Addis Fortune).
        Setting::put(Setting::RENT_CAP_PERCENT, '11.5');
    }
}
