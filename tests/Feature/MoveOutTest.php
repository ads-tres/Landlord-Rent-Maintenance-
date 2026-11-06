<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\MoveOut;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payments\PaymentRecorder;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MoveOutTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Unit $unit;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-11-15 10:00');
        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian', 'name' => 'Abel']);
        $property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);
        $this->unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 1', 'status' => 'occupied']);
        $this->lease = Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $this->unit->id,
            'start_date' => '2026-08-01', 'end_date' => '2028-07-31', 'monthly_rent' => 1_000_000,
            'deposit' => 2_000_000, 'due_day' => 1,
        ]);
        app(RentSchedule::class)->sync($this->lease);
        $this->pay(2_000_000, '2026-09-01'); // August and September
        $this->actingAsLandlord($this->landlord);
    }

    private function pay(int $amount, string $date): void
    {
        app(PaymentRecorder::class)->record($this->lease->refresh(), ['amount' => $amount, 'paid_at' => $date, 'method' => 'cash']);
    }

    private function start(string $date = '2026-11-30'): array
    {
        return $this->postJson("/api/v1/leases/{$this->lease->id}/move-out", [
            'moved_out_on' => $date, 'condition_notes' => 'Wall paint scratched in the bedroom', 'keys_returned' => true,
        ])->assertSuccessful()->json('data');
    }

    public function test_deposit_covers_unpaid_rent_and_the_rest_is_owed(): void
    {
        $m = $this->start();
        $this->assertSame(['unpaid_rent', 'unpaid_rent'], array_column($m['deductions'], 'kind'));
        $this->assertSame(['October 2026', 'November 2026'], array_column($m['deductions'], 'description'));
        $this->assertSame(2_000_000, $m['unpaid_total']);

        $this->post("/api/v1/move-outs/{$m['id']}/photos", ['photo' => UploadedFile::fake()->image('wall.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(1, 'data.photos');
        $m = $this->postJson("/api/v1/move-outs/{$m['id']}/deductions", ['kind' => 'damage', 'description' => 'Repaint bedroom', 'amount' => 300_000])
            ->assertOk()->json('data');
        $this->assertSame([2_000_000, 2_000_000, 0, 0, 300_000], [$m['deposit'], $m['deposit_applied'], $m['deposit_kept'], $m['refund_amount'], $m['balance_owed']]);

        // Nothing to refund, so no refund method is needed.
        $m = $this->postJson("/api/v1/move-outs/{$m['id']}/settle")->assertOk()->json('data');
        $this->assertSame('settled', $m['status']);

        $this->lease->refresh();
        $this->assertSame(Lease::STATUS_ENDED, $this->lease->status);
        $this->assertSame('2026-11-30', $this->lease->terminated_on->toDateString());
        $this->assertSame('vacant', $this->unit->refresh()->status);
        $this->assertSame(0, Charge::query()->where('lease_id', $this->lease->id)->where('status', '!=', 'paid')->count());
        $deposit = Payment::query()->where('method', 'deposit')->sole();
        $this->assertSame([2_000_000, 'deposit'], [$deposit->amount, $deposit->source]);

        // The tenant gets the statement link; the page shows the deductions and what's owed.
        $this->assertStringContainsString('/mo/', $this->sms->sent[0]['message']);
        $this->assertStringContainsString('refund Br 0', $this->sms->sent[0]['message']);
        $token = MoveOut::query()->withoutGlobalScopes()->value('statement_token');
        $this->get("/mo/{$token}?lang=en")->assertOk()
            ->assertSee('Deposit statement')
            ->assertSee('Repaint bedroom')
            ->assertSee('Br 3,000 is still owed')
            ->assertSee('Wall paint scratched');
        $this->get("/mo/{$token}?lang=am")->assertOk()->assertSee('የተቀማጭ ሂሳብ መግለጫ');

        $this->get("/api/v1/move-outs/{$m['id']}/statement")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->getJson("/api/v1/leases/{$this->lease->id}")->assertJsonPath('data.move_out.status', 'settled');

        // Settled move-outs are final; deposit payments can't be removed from the payments list.
        $this->patchJson("/api/v1/move-outs/{$m['id']}", ['keys_returned' => false])->assertUnprocessable();
        $this->deleteJson("/api/v1/payments/{$deposit->id}")->assertStatus(409);
    }

    public function test_refund_follows_payments_made_before_settling(): void
    {
        $this->pay(1_000_000, '2026-10-02'); // October
        $m = $this->start();
        $this->assertSame(1_000_000, $m['unpaid_total']);

        $m = $this->postJson("/api/v1/move-outs/{$m['id']}/deductions", ['kind' => 'cleaning', 'description' => 'Deep clean', 'amount' => 50_000])->json('data');
        $this->assertSame(950_000, $m['refund_amount']);

        $this->pay(400_000, '2026-11-20'); // part of November, after the move-out was started

        $this->postJson("/api/v1/move-outs/{$m['id']}/settle", ['notify_tenant' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('refund_method');

        $m = $this->postJson("/api/v1/move-outs/{$m['id']}/settle", ['refund_method' => 'telebirr', 'refund_reference' => 'TX123', 'notify_tenant' => false])
            ->assertOk()->json('data');
        $this->assertSame([600_000, 50_000, 1_350_000, 0, 'telebirr'], [$m['deposit_applied'], $m['deposit_kept'], $m['refund_amount'], $m['balance_owed'], $m['refund_method']]);
        $this->assertCount(0, $this->sms->sent);

        $this->postJson("/api/v1/move-outs/{$m['id']}/send")->assertNoContent();
        $this->assertStringContainsString('refund Br 13,500', $this->sms->sent[0]['message']);
    }

    public function test_deductions_and_dates_can_change_until_settled(): void
    {
        $m = $this->start('2026-10-31');
        $this->assertCount(1, $m['deductions']); // October only

        $chargeDeduction = $m['deductions'][0]['id'];
        $this->deleteJson("/api/v1/move-out-deductions/{$chargeDeduction}")->assertUnprocessable();

        $m = $this->postJson("/api/v1/move-outs/{$m['id']}/deductions", ['kind' => 'keys', 'description' => 'Lost gate key', 'amount' => 20_000])->json('data');
        $keys = collect($m['deductions'])->firstWhere('kind', 'keys')['id'];
        $this->deleteJson("/api/v1/move-out-deductions/{$keys}")->assertOk()->assertJsonCount(1, 'data.deductions');

        // A later date brings in November; starting again returns the same move-out.
        $again = $this->start('2026-12-15');
        $this->assertSame($m['id'], $again['id']);
        $this->assertSame(3_000_000, $again['unpaid_total']); // October to December

        $this->postJson("/api/v1/move-outs/{$m['id']}/deductions", ['kind' => 'unpaid_rent', 'description' => 'x', 'amount' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->postJson("/api/v1/leases/{$this->lease->id}/move-out", ['moved_out_on' => '2029-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('moved_out_on');
    }

    public function test_ended_lease_and_other_landlords_are_refused(): void
    {
        $m = $this->start();

        $other = User::factory()->create();
        $this->actingAsLandlord($other);
        $this->getJson("/api/v1/move-outs/{$m['id']}")->assertNotFound();
        $this->postJson("/api/v1/move-outs/{$m['id']}/settle")->assertNotFound();
        $this->postJson("/api/v1/leases/{$this->lease->id}/move-out", ['moved_out_on' => '2026-11-30'])->assertNotFound();

        $this->actingAsLandlord($this->landlord);
        $this->postJson("/api/v1/move-outs/{$m['id']}/settle")->assertOk();
        $this->postJson("/api/v1/leases/{$this->lease->id}/move-out", ['moved_out_on' => '2026-11-30'])->assertUnprocessable();
        $this->get('/mo/abcdefghij')->assertNotFound();
    }
}
