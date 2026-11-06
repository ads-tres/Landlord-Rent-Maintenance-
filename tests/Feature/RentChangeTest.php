<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentChange;
use App\Models\Setting;
use App\Models\Unit;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentChangeTest extends TestCase
{
    use RefreshDatabase;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put(Setting::RENT_CAP_PERCENT, '11.5');
        $this->travelTo('2027-07-10');
        $landlord = $this->actingAsLandlord();
        $landlord->update(['calendar_pref' => 'gregorian']);
        $this->lease = Lease::factory()->create([
            'landlord_id' => $landlord->id,
            'unit_id' => Unit::factory()->create(['landlord_id' => $landlord->id, 'property_id' => Property::factory()->create(['landlord_id' => $landlord->id])->id])->id,
            'start_date' => '2026-07-01',
            'end_date' => '2028-06-30',
            'monthly_rent' => 1_000_000,
        ]);
        app(RentSchedule::class)->sync($this->lease);
    }

    public function test_rules_endpoint_returns_the_current_cap(): void
    {
        $this->getJson('/api/v1/rules')
            ->assertOk()
            ->assertJsonPath('data.rent_cap_percent', 11.5)
            ->assertJsonPath('data.min_lease_years', 2)
            ->assertJsonPath('data.rent_notice_days', 30)
            ->assertJsonPath('data.earliest_increase_date', '2027-08-09');
    }

    public function test_increase_within_the_cap_is_saved_and_reprices_unpaid_charges(): void
    {
        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 1_115_000,
            'effective_date' => '2027-07-01',
            'tenant_agreed' => true,
        ])->assertOk()
            ->assertJsonPath('data.monthly_rent', 1_115_000)
            ->assertJsonPath('data.rent_changes.0.old_rent', 1_000_000)
            ->assertJsonPath('data.rent_changes.0.cap_percent', 11.5);

        $this->assertSame(1_000_000, Charge::query()->where('period', '2027-06')->value('amount_due'));
        $this->assertSame(1_115_000, Charge::query()->where('period', '2027-07')->value('amount_due'));

        // Charges created later use the new rent.
        $this->travelTo('2027-08-02');
        $this->artisan('rent:generate-charges')->assertSuccessful();
        $this->assertSame(1_115_000, Charge::query()->where('period', '2027-08')->value('amount_due'));
    }

    public function test_increase_above_the_cap_is_refused(): void
    {
        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 1_120_000,
            'effective_date' => '2027-07-01',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('new_rent')
            ->assertJsonPath('errors.new_rent.0', fn (string $m) => str_contains($m, 'Br 11,150.00'));

        $this->assertSame(1_000_000, $this->lease->fresh()->monthly_rent);
        $this->assertDatabaseCount('rent_changes', 0);
    }

    public function test_cap_comes_from_settings(): void
    {
        Setting::put(Setting::RENT_CAP_PERCENT, '15');

        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 1_150_000,
            'effective_date' => '2027-07-01',
            'tenant_agreed' => true,
        ])->assertOk();
    }

    public function test_future_rent_change_applies_from_its_month(): void
    {
        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 1_100_000,
            'effective_date' => '2027-09-01',
        ])->assertOk()
            ->assertJsonPath('data.monthly_rent', 1_000_000);

        $this->travelTo('2027-09-01 01:00');
        $this->artisan('rent:generate-charges')->assertSuccessful();

        $this->assertSame(1_000_000, Charge::query()->where('period', '2027-08')->value('amount_due'));
        $this->assertSame(1_100_000, Charge::query()->where('period', '2027-09')->value('amount_due'));
        $this->assertSame(1_100_000, $this->lease->fresh()->monthly_rent);
    }

    public function test_rent_decrease_is_allowed(): void
    {
        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 900_000,
            'effective_date' => '2027-07-01',
        ])->assertOk();
    }

    public function test_an_increase_needs_notice_unless_the_tenant_agreed(): void
    {
        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 1_100_000,
            'effective_date' => '2027-08-01',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.effective_date.0', fn (string $m) => str_contains($m, '30 days') && str_contains($m, '9 August 2027'));

        Setting::put(Setting::RENT_NOTICE_DAYS, '15');
        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 1_100_000,
            'effective_date' => '2027-08-01',
        ])->assertOk()
            ->assertJsonPath('data.rent_changes.0.notice_days', 22)
            ->assertJsonPath('data.rent_changes.0.tenant_agreed', false)
            ->assertJsonPath('data.rent_changes.0.upcoming', true);
    }

    public function test_the_tenant_is_told_and_reminded_a_week_before(): void
    {
        $this->lease->landlord->update(['locale' => 'en', 'name' => 'Abebe']);
        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 1_100_000,
            'effective_date' => '2027-09-01',
        ])->assertOk();

        $this->assertCount(1, $this->sms->sent);
        $this->assertStringContainsString('changes from Br 10,000 to Br 11,000 starting 1 September 2027', $this->sms->sent[0]['message']);
        $change = RentChange::query()->sole();
        $this->assertNotNull($change->notified_at);

        $this->travelTo('2027-08-25 09:40');
        $this->artisan('rent:change-reminders')->assertSuccessful();
        $this->artisan('rent:change-reminders')->assertSuccessful();
        $this->assertCount(2, $this->sms->sent);
        $this->assertStringContainsString('Reminder: from 1 September 2027', $this->sms->sent[1]['message']);
    }

    public function test_the_landlord_can_skip_the_text(): void
    {
        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 1_100_000,
            'effective_date' => '2027-09-01',
            'notify_tenant' => false,
        ])->assertOk()->assertJsonPath('data.rent_changes.0.notified_at', null);

        $this->assertCount(0, $this->sms->sent);
    }

    public function test_a_planned_change_can_be_cancelled_until_it_starts(): void
    {
        $this->lease->landlord->update(['locale' => 'en']);
        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 1_100_000,
            'effective_date' => '2027-09-01',
        ])->assertOk();
        $change = RentChange::query()->sole();

        // The September charge was already made at the new rent; cancelling puts it back.
        $this->travelTo('2027-08-20');
        app(RentSchedule::class)->sync($this->lease->fresh(), now()->addMonth());
        $this->assertSame(1_100_000, Charge::query()->where('period', '2027-09')->value('amount_due'));

        $this->deleteJson("/api/v1/leases/{$this->lease->id}/rent-changes/{$change->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.rent_changes');
        $this->assertSame(1_000_000, Charge::query()->where('period', '2027-09')->value('amount_due'));
        $this->assertStringContainsString('is cancelled. Rent stays Br 10,000', $this->sms->sent[1]['message']);

        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 1_100_000, 'effective_date' => '2027-08-01', 'tenant_agreed' => true,
        ])->assertOk();
        $started = RentChange::query()->sole();
        $this->deleteJson("/api/v1/leases/{$this->lease->id}/rent-changes/{$started->id}")->assertStatus(409);
    }

    public function test_notice_letter_downloads_in_both_languages(): void
    {
        $this->postJson("/api/v1/leases/{$this->lease->id}/rent-change", [
            'new_rent' => 1_100_000,
            'effective_date' => '2027-09-01',
        ])->assertOk();
        $change = RentChange::query()->sole();

        $response = $this->get("/api/v1/leases/{$this->lease->id}/rent-changes/{$change->id}/notice?lang=both")->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $other = Lease::factory()->create(['landlord_id' => $this->lease->landlord_id]);
        $this->get("/api/v1/leases/{$other->id}/rent-changes/{$change->id}/notice")->assertNotFound();
    }
}
