<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payments\PaymentRecorder;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaseStatementTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-12-10 10:00');
        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian', 'name' => 'Abel']);
        $property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 1', 'status' => 'occupied']);
        $this->lease = Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $unit->id,
            'start_date' => '2026-09-01', 'end_date' => '2028-08-31', 'monthly_rent' => 1_000_000, 'due_day' => 1,
        ]);
        $this->lease->tenant->update(['full_name' => 'Almaz Tesfaye']);
        app(RentSchedule::class)->sync($this->lease); // Sep–Dec charged
        app(PaymentRecorder::class)->record($this->lease, ['amount' => 1_000_000, 'paid_at' => '2026-09-02', 'method' => 'cash']);
        app(PaymentRecorder::class)->record($this->lease, ['amount' => 1_500_000, 'paid_at' => '2026-10-05', 'method' => 'telebirr', 'reference' => 'TB55']);
        $this->actingAsLandlord($this->landlord);
    }

    public function test_whole_lease_statement_has_a_running_balance(): void
    {
        $s = $this->getJson("/api/v1/leases/{$this->lease->id}/statement")->assertOk()->json('data');

        $this->assertSame(['2026-09-01', '2026-12-10'], [$s['from'], $s['to']]);
        $this->assertSame(0, $s['opening']);
        $this->assertSame([4_000_000, 2_500_000, 1_500_000], [$s['charged'], $s['paid'], $s['closing']]);
        $this->assertSame('Br 15,000 owed', $s['closing_display']);
        $this->assertSame(
            ['charge', 'payment', 'charge', 'payment', 'charge', 'charge'],
            array_column($s['lines'], 'kind'),
        );
        $this->assertSame([1_000_000, 0, 1_000_000, -500_000, 500_000, 1_500_000], array_column($s['lines'], 'balance'));
        $this->assertSame('Rent for September 2026', $s['lines'][0]['description']);
        $this->assertStringContainsString('Payment (Telebirr), receipt', $s['lines'][3]['description']);
        $this->assertStringEndsWith('· TB55', $s['lines'][3]['description']);
        $this->assertSame('Br 5,000 paid in advance', $s['lines'][3]['balance_display']);
    }

    public function test_a_period_brings_the_earlier_balance_forward(): void
    {
        $s = $this->getJson("/api/v1/leases/{$this->lease->id}/statement?from=2026-11-01&to=2026-11-30")->json('data');

        $this->assertSame(-500_000, $s['opening']); // paid Br 5,000 ahead by the end of October
        $this->assertCount(1, $s['lines']);
        $this->assertSame(500_000, $s['closing']);

        $this->getJson("/api/v1/leases/{$this->lease->id}/statement?from=2026-11-01&to=2026-10-01")->assertUnprocessable();
    }

    public function test_pdf_in_both_languages_and_access(): void
    {
        foreach (['en', 'am'] as $language) {
            $this->get("/api/v1/leases/{$this->lease->id}/statement?format=pdf&language={$language}")
                ->assertOk()->assertHeader('content-type', 'application/pdf');
        }
        $am = $this->getJson("/api/v1/leases/{$this->lease->id}/statement?language=am")->json('data');
        $this->assertStringContainsString('ዕዳ', $am['closing_display']);

        $caretaker = User::factory()->create(['role' => User::ROLE_CARETAKER]);
        $caretaker->forceFill(['landlord_id' => $this->landlord->id])->save();
        $this->actingAsLandlord($caretaker);
        $this->getJson("/api/v1/leases/{$this->lease->id}/statement")->assertOk();

        $this->actingAsLandlord(User::factory()->create());
        $this->getJson("/api/v1/leases/{$this->lease->id}/statement")->assertNotFound();
    }
}
