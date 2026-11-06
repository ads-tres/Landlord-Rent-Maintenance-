<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payments\PaymentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function steps(): array
    {
        return collect($this->getJson('/api/v1/onboarding')->assertOk()->json('data.steps'))->pluck('done', 'key')->all();
    }

    public function test_steps_tick_off_as_the_landlord_uses_the_app(): void
    {
        $this->travelTo('2026-11-15 10:00');
        $landlord = $this->actingAsLandlord(User::factory()->create(['name' => null]));

        $this->getJson('/api/v1/onboarding')
            ->assertJsonPath('data.done_count', 0)
            ->assertJsonPath('data.total', 7)
            ->assertJsonPath('data.completed', false)
            ->assertJsonPath('data.first_lease_id', null);

        $landlord->update(['name' => 'Abel']);
        $property = Property::factory()->for($landlord, 'landlord')->create();
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $landlord->id, 'status' => 'occupied']);
        $lease = Lease::factory()->create(['landlord_id' => $landlord->id, 'unit_id' => $unit->id, 'start_date' => '2026-11-01', 'end_date' => '2028-10-31']);

        $this->assertSame(
            ['profile' => true, 'property' => true, 'units' => true, 'tenant' => true, 'lease' => true, 'payment' => false, 'tenant_page' => false, 'telegram' => false, 'caretaker' => false],
            $this->steps(),
        );
        $this->getJson('/api/v1/onboarding')->assertJsonPath('data.first_lease_id', $lease->id);

        app(PaymentRecorder::class)->record($lease, ['amount' => 100_000, 'paid_at' => '2026-11-02', 'method' => 'cash']);
        $this->postJson("/api/v1/leases/{$lease->id}/portal-link/send")->assertOk();

        $this->getJson('/api/v1/onboarding')
            ->assertJsonPath('data.done_count', 7)
            ->assertJsonPath('data.completed', true); // Telegram and a caretaker are optional
    }

    public function test_checklist_can_be_hidden_and_is_for_landlords_only(): void
    {
        $landlord = $this->actingAsLandlord(User::factory()->create());
        $this->postJson('/api/v1/onboarding/dismiss')->assertOk()->assertJsonPath('data.dismissed', true);
        $this->getJson('/api/v1/onboarding')->assertJsonPath('data.dismissed', true);

        $caretaker = User::factory()->create(['role' => User::ROLE_CARETAKER]);
        $caretaker->forceFill(['landlord_id' => $landlord->id])->save();
        $this->actingAsLandlord($caretaker);
        $this->getJson('/api/v1/onboarding')->assertForbidden();
    }
}
