<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\PaymentPlan;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payments\PaymentRecorder;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArrearsTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-20 10:00');
        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian', 'name' => 'Abel', 'phone' => '+251911000001']);
        $property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 1', 'status' => 'occupied']);
        $this->lease = Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $unit->id,
            'start_date' => '2026-09-01', 'end_date' => '2028-08-31', 'monthly_rent' => 1_000_000, 'due_day' => 1,
        ]);
        $this->lease->tenant->update(['full_name' => 'Almaz Tesfaye']);
        app(RentSchedule::class)->sync($this->lease);
        app(PaymentRecorder::class)->record($this->lease, ['amount' => 1_000_000, 'paid_at' => '2026-09-02', 'method' => 'cash']);
        // October (50 days late) and November (19 days late) unpaid.
        $this->actingAsLandlord($this->landlord);
    }

    public function test_arrears_list_suggests_the_next_step(): void
    {
        $this->getJson('/api/v1/arrears')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tenant_name', 'Almaz Tesfaye')
            ->assertJsonPath('data.0.owed', 2_000_000)
            ->assertJsonPath('data.0.days_overdue', 50)
            ->assertJsonPath('data.0.months_behind', 2)
            ->assertJsonPath('data.0.suggested', 'reminder');

        // Steps go in order: reminder, then notice, then the demand letter.
        $this->postJson("/api/v1/leases/{$this->lease->id}/arrears/actions", ['kind' => 'reminder']);
        $this->getJson('/api/v1/arrears')->assertJsonPath('data.0.suggested', 'notice');
        $this->postJson("/api/v1/leases/{$this->lease->id}/arrears/actions", ['kind' => 'notice']);
        $this->getJson('/api/v1/arrears')->assertJsonPath('data.0.suggested', 'demand_letter');
    }

    public function test_nothing_is_suggested_before_15_days(): void
    {
        $this->travelTo('2026-10-10 10:00'); // October rent 9 days late
        $this->getJson('/api/v1/arrears')->assertJsonPath('data.0.days_overdue', 9)->assertJsonPath('data.0.suggested', null);
    }

    public function test_reminder_and_notice_are_texted_and_logged(): void
    {
        $this->postJson("/api/v1/leases/{$this->lease->id}/arrears/actions", ['kind' => 'reminder'])->assertOk()
            ->assertJsonPath('data.actions.0.kind', 'reminder')
            ->assertJsonPath('data.actions.0.amount_owed', 2_000_000);
        $this->assertStringContainsString('friendly reminder: rent of Br 20,000 for Room 1 is 50 days overdue', $this->sms->sent[0]['message']);
        $this->assertStringContainsString('/t/', $this->sms->sent[0]['message']);

        $this->postJson("/api/v1/leases/{$this->lease->id}/arrears/actions", ['kind' => 'notice'])->assertOk();
        $this->assertStringContainsString('Notice: rent of Br 20,000', $this->sms->sent[1]['message']);

        $this->postJson("/api/v1/leases/{$this->lease->id}/arrears/actions", ['kind' => 'note', 'note' => 'Called, will pay Friday'])
            ->assertOk()->assertJsonPath('data.actions.0.note', 'Called, will pay Friday')->assertJsonCount(3, 'data.actions');
        $this->postJson("/api/v1/leases/{$this->lease->id}/arrears/actions", ['kind' => 'note'])->assertUnprocessable();
    }

    public function test_demand_letter_is_a_pdf_and_is_logged(): void
    {
        $this->get("/api/v1/leases/{$this->lease->id}/arrears/demand-letter")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->getJson("/api/v1/leases/{$this->lease->id}/arrears")
            ->assertJsonPath('data.actions.0.kind', 'demand_letter')
            ->assertJsonPath('data.suggested', null)
            ->assertJsonCount(2, 'data.charges');
        $this->withHeader('Accept-Language', 'am')->get("/api/v1/leases/{$this->lease->id}/arrears/demand-letter")->assertOk();
    }

    public function test_payment_plan_tracks_progress_reminds_and_completes(): void
    {
        $this->postJson("/api/v1/leases/{$this->lease->id}/payment-plan", ['instalments' => [
            ['due_date' => '2026-11-25', 'amount' => 1_000_000],
            ['due_date' => '2026-12-25', 'amount' => 500_000],
        ]])->assertUnprocessable()->assertJsonValidationErrors('instalments');

        $this->postJson("/api/v1/leases/{$this->lease->id}/payment-plan", ['instalments' => [
            ['due_date' => '2026-11-25', 'amount' => 1_000_000],
            ['due_date' => '2026-12-25', 'amount' => 1_000_000],
        ]])->assertOk()
            ->assertJsonPath('data.plan.total', 2_000_000)
            ->assertJsonPath('data.plan.paid', 0)
            ->assertJsonPath('data.plan.next.due_date', '2026-11-25')
            ->assertJsonPath('data.suggested', null);
        $this->assertStringContainsString('2 instalments, first Br 10,000 on 25 November 2026', $this->sms->sent[0]['message']);

        // The day before the first instalment.
        $this->travelTo('2026-11-24 09:00');
        $this->artisan('arrears:plans')->expectsOutput('Reminded 1, completed 0, broken 0.');
        $this->assertStringContainsString('instalment of Br 10,000 for Room 1 is due tomorrow', $this->sms->sent[1]['message']);
        $this->artisan('arrears:plans')->expectsOutput('Reminded 0, completed 0, broken 0.');

        app(PaymentRecorder::class)->record($this->lease->refresh(), ['amount' => 1_000_000, 'paid_at' => '2026-11-24', 'method' => 'cash']);
        $this->getJson("/api/v1/leases/{$this->lease->id}/arrears")
            ->assertJsonPath('data.plan.paid', 1_000_000)
            ->assertJsonPath('data.plan.instalments.0.covered', true)
            ->assertJsonPath('data.plan.next.due_date', '2026-12-25');

        app(PaymentRecorder::class)->record($this->lease->refresh(), ['amount' => 1_000_000, 'paid_at' => '2026-11-26', 'method' => 'cash']);
        $this->artisan('arrears:plans')->expectsOutput('Reminded 0, completed 1, broken 0.');
        $this->assertSame('completed', PaymentPlan::query()->value('status'));
    }

    public function test_a_plan_a_week_behind_is_broken(): void
    {
        $this->postJson("/api/v1/leases/{$this->lease->id}/payment-plan", ['instalments' => [
            ['due_date' => '2026-11-25', 'amount' => 2_000_000],
        ], 'notify_tenant' => false])->assertOk();

        $this->travelTo('2026-12-03 09:00');
        $this->artisan('arrears:plans')->expectsOutput('Reminded 0, completed 0, broken 1.');
        $this->getJson("/api/v1/leases/{$this->lease->id}/arrears")
            ->assertJsonPath('data.plan', null)
            ->assertJsonPath('data.actions.0.kind', 'note');
    }

    public function test_caretakers_can_remind_but_not_issue_letters_or_plans(): void
    {
        $caretaker = User::factory()->create(['role' => User::ROLE_CARETAKER, 'name' => 'Bekele']);
        $caretaker->forceFill(['landlord_id' => $this->landlord->id])->save();
        $this->actingAsLandlord($caretaker);

        $this->postJson("/api/v1/leases/{$this->lease->id}/arrears/actions", ['kind' => 'reminder'])->assertOk()->assertJsonPath('data.actions.0.by', 'Bekele');
        $this->get("/api/v1/leases/{$this->lease->id}/arrears/demand-letter", ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson("/api/v1/leases/{$this->lease->id}/payment-plan", ['instalments' => [['due_date' => '2026-12-01', 'amount' => 2_000_000]]])->assertForbidden();
    }
}
