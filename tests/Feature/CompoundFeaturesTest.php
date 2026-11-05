<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\Property;
use App\Models\TelegramLink;
use App\Models\Unit;
use App\Models\User;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CompoundFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Property $property;

    /** @var array<string, Unit> */
    private array $units = [];

    /** @var array<string, Lease> */
    private array $leases = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-11-15 10:00');
        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian', 'name' => 'Abel']);
        $this->property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);

        // Rooms 1–3 are let; Room 4 is vacant.
        foreach (['Room 1', 'Room 2', 'Room 3', 'Room 4'] as $label) {
            $this->units[$label] = Unit::factory()->for($this->property)->create(['landlord_id' => $this->landlord->id, 'label' => $label]);
        }
        foreach (['Room 1', 'Room 2', 'Room 3'] as $label) {
            $this->units[$label]->update(['status' => 'occupied']);
            $this->leases[$label] = Lease::factory()->create([
                'landlord_id' => $this->landlord->id, 'unit_id' => $this->units[$label]->id,
                'start_date' => '2026-11-01', 'end_date' => '2028-10-31', 'monthly_rent' => 1_000_000, 'due_day' => 20,
            ]);
            app(RentSchedule::class)->sync($this->leases[$label]);
        }
        $this->actingAsLandlord($this->landlord);
    }

    private function meter(string $method, array $shares = []): int
    {
        $units = collect(['Room 1', 'Room 2', 'Room 3', 'Room 4'])->map(fn ($l) => [
            'unit_id' => $this->units[$l]->id,
            'share_percent' => $shares[$l] ?? null,
        ])->all();

        return $this->postJson("/api/v1/properties/{$this->property->id}/meters", [
            'name' => 'Main electricity', 'kind' => 'electricity', 'split_method' => $method, 'units' => $units,
        ])->assertCreated()->json('data.id');
    }

    private function bill(int $meterId, array $extra = []): TestResponse
    {
        return $this->postJson("/api/v1/meters/{$meterId}/bills", [
            'period' => '2026-11', 'amount' => 100_000, 'due_date' => '2026-11-25', ...$extra,
        ]);
    }

    private function amounts(array $lines): array
    {
        return collect($lines)->mapWithKeys(fn ($l) => [$l['unit_label'] => $l['amount']])->all();
    }

    public function test_equal_split_puts_the_vacant_units_share_on_the_landlord(): void
    {
        $meter = $this->meter('equal');

        // Br 1,000.01 over 4 units: the extra santim goes to one unit and the parts add up exactly.
        $preview = $this->bill($meter, ['amount' => 100_001, 'dry_run' => true])->assertOk()->json('data');
        $this->assertSame(['Room 1' => 25_001, 'Room 2' => 25_000, 'Room 3' => 25_000, 'Room 4' => 25_000], $this->amounts($preview['lines']));
        $this->assertSame(25_000, $preview['landlord_share']);
        $this->assertSame(0, Charge::query()->where('type', 'utility')->count()); // dry run saves nothing

        $this->bill($meter, ['amount' => 100_001])
            ->assertCreated()
            ->assertJsonCount(3, 'data.charges')
            ->assertJsonPath('data.landlord_share', 25_000);
        $this->assertSame(75_001, (int) Charge::query()->where('type', 'utility')->sum('amount_due'));
    }

    public function test_percent_split_and_shares_must_total_100(): void
    {
        $this->postJson("/api/v1/properties/{$this->property->id}/meters", [
            'name' => 'Water', 'kind' => 'water', 'split_method' => 'percent',
            'units' => [['unit_id' => $this->units['Room 1']->id, 'share_percent' => 60], ['unit_id' => $this->units['Room 2']->id, 'share_percent' => 30]],
        ])->assertUnprocessable()->assertJsonValidationErrors('units');

        $meter = $this->meter('percent', ['Room 1' => 50, 'Room 2' => 30, 'Room 3' => 20, 'Room 4' => 0]);
        $lines = $this->bill($meter, ['dry_run' => true])->json('data.lines');
        $this->assertSame(['Room 1' => 50_000, 'Room 2' => 30_000, 'Room 3' => 20_000, 'Room 4' => 0], $this->amounts($lines));
    }

    public function test_submeter_split_follows_readings(): void
    {
        $meter = $this->meter('submeter');
        $readings = [
            $this->units['Room 1']->id => ['previous' => 100, 'current' => 160], // 60
            $this->units['Room 2']->id => ['previous' => 50, 'current' => 80],   // 30
            $this->units['Room 3']->id => ['previous' => 10, 'current' => 20],   // 10
            $this->units['Room 4']->id => ['previous' => 5, 'current' => 5],     // 0
        ];

        $this->bill($meter, ['readings' => $readings])->assertCreated();
        $charges = Charge::query()->where('type', 'utility')->with('lease.unit')->get()->mapWithKeys(fn ($c) => [$c->lease->unit->label => $c->amount_due])->all();
        $this->assertSame(['Room 1' => 60_000, 'Room 2' => 30_000, 'Room 3' => 10_000], $charges);

        unset($readings[$this->units['Room 3']->id]);
        $this->bill($meter, ['readings' => $readings])->assertUnprocessable()->assertJsonValidationErrors('readings');
        $readings[$this->units['Room 3']->id] = ['previous' => 20, 'current' => 10];
        $this->bill($meter, ['readings' => $readings])->assertUnprocessable()->assertJsonValidationErrors('readings');
    }

    public function test_tenants_are_told_their_share_and_it_shows_in_the_rent_list(): void
    {
        $meter = $this->meter('equal');
        $this->bill($meter, ['photo' => UploadedFile::fake()->image('bill.jpg')])->assertCreated()->assertJsonPath('data.photo_url', fn ($u) => $u !== null);

        $this->assertCount(3, $this->sms->sent);
        $this->assertStringContainsString('Your share of the Electricity bill for November 2026: Br 250 for Room 1, due 25 November 2026', $this->sms->sent[0]['message']);

        $this->getJson('/api/v1/charges?period=2026-11&lease_id='.$this->leases['Room 1']->id)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'rent')
            ->assertJsonPath('data.1.type', 'utility')
            ->assertJsonPath('data.1.utility_kind', 'electricity');
    }

    public function test_payments_cover_rent_and_utility_and_receipts_label_them(): void
    {
        $meter = $this->meter('equal');
        $this->bill($meter, ['notify_tenants' => false]);

        $url = $this->postJson('/api/v1/payments', [
            'lease_id' => $this->leases['Room 1']->id, 'amount' => 1_025_000, 'paid_at' => '2026-11-15', 'method' => 'cash',
        ])->assertCreated()->assertJsonCount(2, 'data.allocations')->json('data.receipt_url');

        $this->assertSame(0, Charge::query()->where('lease_id', $this->leases['Room 1']->id)->where('status', '!=', 'paid')->count());
        $this->get($url.'?lang=en')->assertSee('Electricity · November 2026')->assertSee('Br 250');

        // A bill someone paid towards can't be removed; an unpaid one can.
        $billId = Charge::query()->where('type', 'utility')->value('utility_bill_id');
        $this->deleteJson("/api/v1/bills/{$billId}")->assertUnprocessable();

        $other = $this->meter('equal');
        $id = $this->bill($other, ['notify_tenants' => false])->json('data.id');
        $this->deleteJson("/api/v1/bills/{$id}")->assertNoContent();
    }

    public function test_announcement_preview_counts_sms_and_sends(): void
    {
        config(['telegram.token' => 'TEST', 'sms.price_per_segment_santim' => 35]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        TelegramLink::query()->create([
            'landlord_id' => $this->landlord->id, 'linkable_type' => $this->leases['Room 1']->tenant->getMorphClass(),
            'linkable_id' => $this->leases['Room 1']->tenant_id, 'chat_id' => 77, 'consented_at' => now(),
        ]);

        $preview = $this->postJson("/api/v1/properties/{$this->property->id}/announcements/preview", ['body' => 'No water tomorrow 8-12.'])
            ->assertOk()->json('data');
        $this->assertSame(3, $preview['recipients']);
        $this->assertSame(1, $preview['telegram']);
        $this->assertSame(2, $preview['sms']);
        $this->assertSame(70, $preview['estimated_cost']);
        $this->assertSame('Bole Compound: No water tomorrow 8-12. - Abel', $preview['text']);
        $this->assertEmpty($this->sms->sent);

        $this->postJson("/api/v1/properties/{$this->property->id}/announcements", ['body' => 'No water tomorrow 8-12.'])->assertCreated();
        $this->assertCount(2, $this->sms->sent);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && $r['chat_id'] === 77);
        $this->getJson("/api/v1/properties/{$this->property->id}/announcements")->assertJsonCount(1, 'data')->assertJsonPath('data.0.sms_count', 2);
    }

    public function test_equipment_service_tracking_and_reminders(): void
    {
        $id = $this->postJson("/api/v1/properties/{$this->property->id}/assets", [
            'kind' => 'generator', 'name' => 'Generator', 'service_interval_days' => 90, 'last_serviced_on' => '2026-08-20',
        ])->assertCreated()->assertJsonPath('data.next_service_on', '2026-11-18')->assertJsonPath('data.days_until_service', 3)->json('data.id');

        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.services_due', 1);
        $this->getJson('/api/v1/assets?due_within_days=7')->assertJsonCount(1, 'data');

        // Landlord on Telegram gets a reminder, at most once a week.
        config(['telegram.token' => 'TEST']);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        TelegramLink::query()->create(['landlord_id' => $this->landlord->id, 'linkable_type' => $this->landlord->getMorphClass(), 'linkable_id' => $this->landlord->id, 'chat_id' => 5, 'consented_at' => now()]);

        $this->artisan('assets:remind')->expectsOutput('Sent 1 service reminder(s).');
        Http::assertSent(fn ($r) => str_contains((string) $r['text'], 'Service due: Generator at Bole Compound, 18 November 2026'));
        $this->artisan('assets:remind')->expectsOutput('Sent 0 service reminder(s).');

        $this->postJson("/api/v1/assets/{$id}/services", ['serviced_on' => '2026-11-15', 'cost' => 350_000, 'notes' => 'Oil change'])
            ->assertOk()
            ->assertJsonPath('data.next_service_on', '2027-02-13')
            ->assertJsonPath('data.services.0.cost', 350_000);
        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.services_due', 0);
    }

    public function test_another_landlord_cannot_use_these(): void
    {
        $meter = $this->meter('equal');
        $asset = Asset::query()->create(['property_id' => $this->property->id, 'kind' => 'pump', 'name' => 'Pump', 'service_interval_days' => 30]);

        $this->actingAsLandlord();

        $this->getJson("/api/v1/properties/{$this->property->id}/meters")->assertNotFound();
        $this->getJson("/api/v1/meters/{$meter}")->assertNotFound();
        $this->bill($meter)->assertNotFound();
        $this->postJson("/api/v1/properties/{$this->property->id}/announcements", ['body' => 'Hi'])->assertNotFound();
        $this->postJson("/api/v1/assets/{$asset->id}/services", ['serviced_on' => '2026-11-15'])->assertNotFound();
        $this->getJson('/api/v1/assets')->assertJsonCount(0, 'data');
    }
}
