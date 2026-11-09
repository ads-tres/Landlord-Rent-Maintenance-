<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    /** @var array<string, Lease> */
    private array $leases = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 11:00');
        $this->landlord = $this->actingAsLandlord();
        $this->landlord->update(['locale' => 'en', 'calendar_pref' => 'gregorian', 'name' => 'Abel']);
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id, 'name' => 'Bole House']);

        foreach (['A' => false, 'B' => false, 'C' => false, 'D' => true] as $name => $optedOut) {
            $unit = Unit::factory()->create(['landlord_id' => $this->landlord->id, 'property_id' => $property->id, 'label' => "Room {$name}"]);
            $tenant = Tenant::factory()->create(['landlord_id' => $this->landlord->id, 'full_name' => "Tenant {$name}", 'reminders_opt_out' => $optedOut]);
            $lease = Lease::factory()->create([
                'landlord_id' => $this->landlord->id, 'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
                'start_date' => '2026-10-01', 'end_date' => '2028-09-30', 'due_day' => 5, 'monthly_rent' => 1_000_000,
            ]);
            app(RentSchedule::class)->sync($lease);
            $this->leases[$name] = $lease;
        }

        // October is paid for everyone; in November B paid part and C paid in full.
        foreach ($this->leases as $name => $lease) {
            $this->pay($lease, 1_000_000);
        }
        $this->pay($this->leases['B'], 400_000);
        $this->pay($this->leases['C'], 1_000_000);
    }

    private function pay(Lease $lease, int $amount): void
    {
        $this->postJson('/api/v1/payments', ['lease_id' => $lease->id, 'amount' => $amount, 'paid_at' => '2026-11-10', 'method' => 'cash', 'send_sms' => false])->assertCreated();
    }

    public function test_preview_lists_who_owes_and_who_is_skipped(): void
    {
        $data = $this->getJson('/api/v1/rent/reminders')->assertOk()->json('data');

        $this->assertSame('2026-11', $data['period']);
        $this->assertSame(2, $data['will_send']);
        $this->assertSame(1, $data['skipped']);
        $this->assertSame(2_600_000, $data['total_owed']);
        $this->assertSame(['Room A', 'Room B', 'Room D'], array_column($data['items'], 'unit'));
        $this->assertSame([null, null, 'opted_out'], array_column($data['items'], 'skip'));
        $this->assertSame(600_000, $data['items'][1]['owed']);
        $this->assertSame('Rent of Br 10,000 for Room A (November 2026) is still unpaid. It was due on 5 November 2026. Please pay soon. - Abel', $data['sample']);
    }

    public function test_send_texts_each_tenant_once_a_day(): void
    {
        $this->postJson('/api/v1/rent/reminders')->assertOk()->assertJsonPath('data.sent', 2)->assertJsonPath('data.skipped', 1);
        $this->assertCount(2, $this->sms->sent);
        $this->assertStringContainsString('Rent of Br 6,000 for Room B', $this->sms->sent[1]['message']);

        $this->postJson('/api/v1/rent/reminders')->assertOk()->assertJsonPath('data.sent', 0)->assertJsonPath('data.skipped', 3);
        $this->getJson('/api/v1/rent/reminders')->assertJsonPath('data.items.0.skip', 'reminded_today');

        $this->travelTo('2026-11-16 11:00');
        $this->postJson('/api/v1/rent/reminders', ['lease_ids' => [$this->leases['B']->id]])->assertOk()->assertJsonPath('data.sent', 1);
        $this->assertCount(3, $this->sms->sent);
    }

    public function test_a_past_month_can_be_chosen(): void
    {
        $this->getJson('/api/v1/rent/reminders?period=2026-10')->assertOk()->assertJsonPath('data.will_send', 0);
        $this->getJson('/api/v1/rent/reminders?period=nope')->assertUnprocessable();
    }
}
