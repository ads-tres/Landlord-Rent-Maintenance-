<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentRemindersTest extends TestCase
{
    use RefreshDatabase;

    private function leaseDueOn(int $dueDay, array $landlord = []): Lease
    {
        $owner = User::factory()->create(['locale' => 'en', 'name' => 'Abel', 'calendar_pref' => 'gregorian', ...$landlord]);
        $property = Property::factory()->for($owner, 'landlord')->create();
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $owner->id, 'label' => 'Room 3']);

        return Lease::factory()->create([
            'landlord_id' => $owner->id,
            'unit_id' => $unit->id,
            'start_date' => '2026-10-01',
            'end_date' => '2028-09-30',
            'due_day' => $dueDay,
        ]);
    }

    public function test_reminders_go_out_three_days_before_and_on_the_due_date_once(): void
    {
        $this->travelTo('2026-11-05 10:00');
        $lease = $this->leaseDueOn(8);
        $this->artisan('rent:generate-charges');

        $this->artisan('rent:send-reminders')->expectsOutput('Queued 1 reminder(s).');
        $this->assertStringContainsString('Reminder: rent of Br 10,000 for Room 3 is due on 8 November 2026', $this->sms->sent[0]['message']);
        $this->assertSame($lease->tenant->phone, $this->sms->sent[0]['phone']);

        // Running again the same day sends nothing new.
        $this->artisan('rent:send-reminders')->expectsOutput('Queued 0 reminder(s).');

        $this->travelTo('2026-11-08 10:00');
        $this->artisan('rent:send-reminders')->expectsOutput('Queued 1 reminder(s).');
        $this->assertStringContainsString('is due today', $this->sms->sent[1]['message']);
    }

    public function test_paid_charges_and_opted_out_landlords_get_no_reminders(): void
    {
        $this->travelTo('2026-11-05 10:00');
        $this->leaseDueOn(8, ['sms_reminders' => false]);
        $paid = $this->leaseDueOn(8);
        $this->artisan('rent:generate-charges');
        $paid->charges()->update(['status' => 'paid']);

        $this->artisan('rent:send-reminders')->expectsOutput('Queued 0 reminder(s).');
        $this->assertEmpty($this->sms->sent);
    }
}
