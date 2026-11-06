<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Property;
use App\Models\TelegramLink;
use App\Models\Unit;
use App\Models\User;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeaseRenewalTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00');
        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian', 'name' => 'Abel']);
        $property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 1', 'status' => 'occupied']);
        $this->lease = Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $unit->id,
            'start_date' => '2025-01-01', 'end_date' => '2026-12-31', 'monthly_rent' => 1_000_000, 'due_day' => 1,
        ]);
        $this->lease->tenant->update(['full_name' => 'Almaz Tesfaye']);
        app(RentSchedule::class)->sync($this->lease);
    }

    public function test_renewal_extends_the_lease_with_rent_within_the_cap(): void
    {
        $this->actingAsLandlord($this->landlord);

        $this->getJson("/api/v1/leases/{$this->lease->id}/renewal-check")->assertOk()
            ->assertJsonPath('data.suggested_end_date', '2028-12-31')
            ->assertJsonPath('data.rent_from', '2027-01-01')
            ->assertJsonPath('data.current_rent', 1_000_000)
            ->assertJsonPath('data.max_rent', 1_115_000);

        $this->postJson("/api/v1/leases/{$this->lease->id}/renew", ['new_end_date' => '2028-12-31', 'new_rent' => 1_200_000])
            ->assertUnprocessable()->assertJsonValidationErrors('new_rent');
        $this->postJson("/api/v1/leases/{$this->lease->id}/renew", ['new_end_date' => '2026-12-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('new_end_date');

        $id = $this->postJson("/api/v1/leases/{$this->lease->id}/renew", ['new_end_date' => '2028-12-31', 'new_rent' => 1_100_000])
            ->assertCreated()
            ->assertJsonPath('data.previous_end_date', '2026-12-31')
            ->assertJsonPath('data.rent_from', '2027-01-01')
            ->json('data.id');

        $this->lease->refresh();
        $this->assertSame('2028-12-31', $this->lease->end_date->toDateString());
        $this->assertStringContainsString('renewed until 31 December 2028. Rent from 1 January 2027: Br 11,000', $this->sms->sent[0]['message']);

        // December keeps the old rent; January onwards the new one.
        app(RentSchedule::class)->sync($this->lease, now()->addMonths(2));
        $this->assertSame(1_000_000, Charge::query()->withoutGlobalScopes()->where('period', '2026-12')->value('amount_due'));
        $this->assertSame(1_100_000, Charge::query()->withoutGlobalScopes()->where('period', '2027-01')->value('amount_due'));

        $this->getJson("/api/v1/leases/{$this->lease->id}/renewals")->assertJsonCount(1, 'data');
        $this->get("/api/v1/lease-renewals/{$id}/addendum")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->getJson("/api/v1/leases/{$this->lease->id}")->assertJsonPath('data.rent_changes.0.new_rent', 1_100_000);
    }

    public function test_same_or_lower_rent_and_quiet_renewal(): void
    {
        $this->actingAsLandlord($this->landlord);
        $this->postJson("/api/v1/leases/{$this->lease->id}/renew", ['new_end_date' => '2027-12-31', 'notify_tenant' => false])
            ->assertCreated()->assertJsonPath('data.new_rent', 1_000_000);
        $this->assertCount(0, $this->sms->sent);
        $this->assertSame(0, $this->lease->rentChanges()->count());

        // The next renewal is too early now (more than 180 days before the end).
        $this->postJson("/api/v1/leases/{$this->lease->id}/renew", ['new_end_date' => '2028-12-31'])
            ->assertUnprocessable()->assertJsonValidationErrors('new_end_date');
    }

    public function test_landlord_is_reminded_60_and_30_days_before_the_end(): void
    {
        config(['telegram.token' => 'TEST']);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        TelegramLink::query()->create(['landlord_id' => $this->landlord->id, 'linkable_type' => $this->landlord->getMorphClass(), 'linkable_id' => $this->landlord->id, 'chat_id' => 5, 'consented_at' => now()]);

        $this->travelTo('2026-10-01 09:00'); // 91 days before
        $this->artisan('leases:renewal-reminders')->expectsOutput('Sent 0 renewal reminder(s).');

        $this->travelTo('2026-11-01 09:00'); // 60 days before
        $this->artisan('leases:renewal-reminders')->expectsOutput('Sent 1 renewal reminder(s).');
        Http::assertSent(fn ($r) => str_contains((string) $r['text'], 'Lease ending in 60 days: Almaz Tesfaye, Room 1 at Bole Compound, ends 31 December 2026.'));
        $this->artisan('leases:renewal-reminders')->expectsOutput('Sent 0 renewal reminder(s).');

        $this->travelTo('2026-12-01 09:00'); // 30 days before
        $this->artisan('leases:renewal-reminders')->expectsOutput('Sent 1 renewal reminder(s).');
        $this->artisan('leases:renewal-reminders')->expectsOutput('Sent 0 renewal reminder(s).');

        // Renewing resets the reminders for the new end date.
        $this->actingAsLandlord($this->landlord);
        $this->postJson("/api/v1/leases/{$this->lease->id}/renew", ['new_end_date' => '2028-12-31', 'notify_tenant' => false])->assertCreated();
        $this->assertNull($this->lease->refresh()->renewal_reminder_days);
    }

    public function test_caretakers_can_check_but_not_renew(): void
    {
        $caretaker = User::factory()->create(['role' => User::ROLE_CARETAKER]);
        $caretaker->forceFill(['landlord_id' => $this->landlord->id])->save();
        $this->actingAsLandlord($caretaker);

        $this->getJson("/api/v1/leases/{$this->lease->id}/renewal-check")->assertOk();
        $this->postJson("/api/v1/leases/{$this->lease->id}/renew", ['new_end_date' => '2028-12-31'])->assertForbidden();
    }
}
