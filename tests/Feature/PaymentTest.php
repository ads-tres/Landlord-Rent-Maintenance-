<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Property;
use App\Models\SmsMessage;
use App\Models\Unit;
use App\Models\User;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00');
        $this->landlord = $this->actingAsLandlord(User::factory()->create(['name' => 'Abel', 'locale' => 'en', 'calendar_pref' => 'gregorian']));
        $property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 3', 'status' => 'occupied']);

        // Br 10,000 a month from September 2026; Sep, Oct, Nov are charged.
        $this->lease = Lease::factory()->create([
            'landlord_id' => $this->landlord->id,
            'unit_id' => $unit->id,
            'start_date' => '2026-09-01',
            'end_date' => '2028-08-31',
            'monthly_rent' => 1_000_000,
            'due_day' => 1,
        ]);
        app(RentSchedule::class)->sync($this->lease);
        $this->artisan('rent:mark-late');
    }

    private function pay(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/payments', [
            'lease_id' => $this->lease->id,
            'amount' => 1_000_000,
            'paid_at' => '2026-11-15',
            'method' => 'telebirr',
            'reference' => 'TB'.fake()->unique()->numerify('########'),
            ...$overrides,
        ]);
    }

    private function statuses(): array
    {
        return Charge::query()->orderBy('period')->pluck('status', 'period')->all();
    }

    public function test_payment_settles_the_oldest_month_first(): void
    {
        $this->pay()
            ->assertCreated()
            ->assertJsonPath('data.allocations.0.period', '2026-09')
            ->assertJsonPath('data.receipt_number', fn ($n) => str_starts_with($n, 'R-'));

        $this->assertSame(['2026-09' => 'paid', '2026-10' => 'late', '2026-11' => 'late'], $this->statuses());
    }

    public function test_partial_payment_leaves_a_balance(): void
    {
        $this->pay(['amount' => 400_000])->assertCreated();
        $this->pay(['amount' => 1_000_000])->assertCreated();

        $sep = Charge::query()->where('period', '2026-09')->first();
        $oct = Charge::query()->where('period', '2026-10')->first();

        $this->assertSame('paid', $sep->status);
        $this->assertSame(400_000, $oct->amountPaid());
        $this->assertSame('late', $oct->status); // past due and not fully paid

        $this->getJson('/api/v1/charges?period=2026-10')->assertJsonPath('data.0.balance', 600_000);
    }

    public function test_advance_payment_creates_and_pays_future_months(): void
    {
        // 3 months owed + 2 months ahead.
        $this->pay(['amount' => 5_000_000])->assertCreated()->assertJsonCount(5, 'data.allocations');

        $this->assertSame([
            '2026-09' => 'paid', '2026-10' => 'paid', '2026-11' => 'paid',
            '2026-12' => 'paid', '2027-01' => 'paid',
        ], $this->statuses());

        // The daily job does not duplicate prepaid months.
        $this->travelTo('2027-01-02');
        $this->artisan('rent:generate-charges');
        $this->assertSame(5, Charge::query()->count());
    }

    public function test_cannot_pay_more_than_the_whole_lease(): void
    {
        $this->pay(['amount' => 25_000_000]) // 24 months is Br 240,000
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(3, Charge::query()->count());
    }

    public function test_reference_is_required_for_telebirr_and_bank_and_must_be_unique(): void
    {
        $this->pay(['reference' => null])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->pay(['method' => 'cash', 'reference' => null])->assertCreated();

        $this->pay(['reference' => 'TB123'])->assertCreated();
        $this->pay(['reference' => 'TB123'])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->pay(['reference' => 'TB123', 'method' => 'bank'])->assertCreated(); // different channel
    }

    public function test_payment_date_cannot_be_in_the_future(): void
    {
        $this->pay(['paid_at' => '2026-11-16'])->assertUnprocessable()->assertJsonValidationErrors('paid_at');
    }

    public function test_tenant_gets_an_sms_receipt_with_a_link(): void
    {
        $response = $this->pay(['amount' => 2_000_000])->assertCreated()->assertJsonPath('data.sms_status', 'sent');

        $sms = $this->sms->sent[0];
        $this->assertSame($this->lease->tenant->phone, $sms['phone']);
        $this->assertStringContainsString('Received Br 20,000 for Room 3 (September 2026 – October 2026)', $sms['message']);
        $this->assertStringContainsString($response->json('data.receipt_url'), $sms['message']);

        $this->assertDatabaseHas('sms_messages', ['kind' => 'receipt', 'status' => 'sent', 'landlord_id' => $this->landlord->id]);
    }

    public function test_sms_and_receipt_follow_the_ethiopian_calendar(): void
    {
        $this->landlord->update(['calendar_pref' => 'ethiopian']);

        $url = $this->pay(['amount' => 2_000_000])->json('data.receipt_url');

        $this->assertStringContainsString('(Nehase 2018–Tikimt 2019)', $this->sms->sent[0]['message']);
        $this->get($url.'?lang=am')->assertSee('6 ኅዳር 2019')->assertSee('መስከረም–ጥቅምት 2019', false);
    }

    public function test_sms_can_be_skipped(): void
    {
        $this->pay(['send_sms' => false])->assertCreated();

        $this->assertEmpty($this->sms->sent);
    }

    public function test_public_receipt_page_shows_the_payment(): void
    {
        $url = $this->pay(['amount' => 1_500_000, 'reference' => 'TB999'])->json('data.receipt_url');

        $html = $this->get($url.'?lang=en')
            ->assertOk()
            ->assertSee('Br 15,000')
            ->assertSee('TB999')
            ->assertSee('September 2026')
            ->assertSee('October 2026')
            ->assertSee('Still to pay on this lease: Br 15,000')
            ->getContent();

        $this->assertLessThan(100 * 1024, strlen($html));
        $this->get('/r/abcdefghij')->assertNotFound();
    }

    public function test_receipt_pdf_downloads_in_amharic(): void
    {
        $id = $this->pay()->json('data.id');

        $response = $this->get("/api/v1/payments/{$id}/receipt", ['Accept-Language' => 'am'])->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_voiding_a_payment_restores_the_charges(): void
    {
        $id = $this->pay(['amount' => 5_000_000])->json('data.id');

        $this->deleteJson("/api/v1/payments/{$id}")->assertNoContent();

        $this->assertSame(['2026-09' => 'late', '2026-10' => 'late', '2026-11' => 'late'], $this->statuses());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_payments_are_listed_per_lease(): void
    {
        $this->pay();
        $this->pay(['method' => 'cash', 'reference' => null]);

        $this->getJson('/api/v1/payments?lease_id='.$this->lease->id)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_another_landlord_cannot_see_or_void_payments(): void
    {
        $id = $this->pay()->json('data.id');

        $this->actingAsLandlord();

        $this->getJson("/api/v1/payments/{$id}")->assertNotFound();
        $this->deleteJson("/api/v1/payments/{$id}")->assertNotFound();
        $this->get("/api/v1/payments/{$id}/receipt")->assertNotFound();
        $this->postJson('/api/v1/payments', [
            'lease_id' => $this->lease->id, 'amount' => 100_000, 'paid_at' => '2026-11-15', 'method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('lease_id');
    }

    public function test_dashboard_totals(): void
    {
        $this->pay(['amount' => 1_500_000]);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.collected_this_month', 1_500_000)
            ->assertJsonPath('data.due_this_month', 1_000_000)
            ->assertJsonPath('data.outstanding', 1_500_000)
            ->assertJsonPath('data.late_tenants', 1)
            ->assertJsonPath('data.units', 1)
            ->assertJsonPath('data.vacant_units', 0);
    }

    public function test_sms_segments_are_counted(): void
    {
        $this->assertSame(1, SmsMessage::segmentsFor(str_repeat('a', 160)));
        $this->assertSame(2, SmsMessage::segmentsFor(str_repeat('a', 161)));
        $this->assertSame(1, SmsMessage::segmentsFor(str_repeat('ሀ', 70)));
        $this->assertSame(2, SmsMessage::segmentsFor(str_repeat('ሀ', 71)));
    }
}
