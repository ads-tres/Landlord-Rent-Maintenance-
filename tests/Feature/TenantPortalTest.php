<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\PayoutAccount;
use App\Models\Property;
use App\Models\SmsMessage;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payments\PaymentRecorder;
use App\Services\Payments\ReceiptNotifier;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Property $property;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-11-15 10:00');
        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian', 'name' => 'Abel', 'phone' => '+251911000001']);
        $this->property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);
        $unit = Unit::factory()->for($this->property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 1', 'status' => 'occupied']);
        $this->lease = Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $unit->id,
            'start_date' => '2026-09-01', 'end_date' => '2028-08-31', 'monthly_rent' => 1_000_000, 'due_day' => 1,
        ]);
        $this->lease->tenant->update(['full_name' => 'Almaz Tesfaye']);
        app(RentSchedule::class)->sync($this->lease);
        app(PaymentRecorder::class)->record($this->lease, ['amount' => 1_000_000, 'paid_at' => '2026-09-02', 'method' => 'telebirr', 'reference' => 'T1']);
        // October and November unpaid.
    }

    private function url(): string
    {
        return '/t/'.$this->lease->refresh()->portal_token;
    }

    public function test_tenant_page_shows_balance_payments_repairs_and_announcements(): void
    {
        (new MaintenanceRequest)->forceFill([
            'landlord_id' => $this->landlord->id, 'unit_id' => $this->lease->unit_id, 'lease_id' => $this->lease->id,
            'title' => 'Kitchen tap', 'category' => 'plumbing', 'status' => 'in_progress', 'source' => 'tenant', 'opened_at' => now()->subDay(),
        ])->save();
        $announcement = new Announcement(['property_id' => $this->property->id, 'body' => 'Water off Saturday 9–12']);
        $announcement->landlord_id = $this->landlord->id;
        $announcement->property_id = $this->property->id;
        $announcement->save();

        $response = $this->get($this->url().'?lang=en')->assertOk();
        $response->assertSee('Hello Almaz')
            ->assertSee('Room 1 · Bole Compound')
            ->assertSee('Overdue')
            ->assertSee('Br 20,000')
            ->assertSee('Rent for December 2026')
            ->assertSee('Telebirr')
            ->assertSee('/r/', false)
            ->assertSee('Kitchen tap')
            ->assertSee('In progress')
            ->assertSee('Water off Saturday')
            ->assertSee('tel:+251911000001', false)
            ->assertDontSee('Pay online');
        $this->assertLessThan(100_000, strlen($response->getContent()));

        $this->get($this->url().'?lang=am')->assertOk()->assertSee('ሰላም Almaz')->assertSee('ያለፈበት');
    }

    public function test_pay_button_only_when_online_rent_is_on(): void
    {
        config(['chapa.secret_key' => 'CHASECK_TEST']);
        $account = new PayoutAccount(['bank_code' => 'x', 'account_name' => 'Abel', 'account_number' => '1000', 'chapa_subaccount_id' => 'sub_1', 'status' => 'active']);
        $account->landlord_id = $this->landlord->id;
        $account->save();
        $this->landlord->update(['online_rent_enabled' => true]);

        $this->get($this->url().'?lang=en')->assertOk()->assertSee('Pay online')->assertSee('/pay/'.$this->lease->pay_token, false);
    }

    public function test_repair_sent_from_the_page_comes_back_to_it(): void
    {
        $this->post('/fix/'.$this->lease->repair_token, ['category' => 'water', 'description' => 'No water', 'back' => 'portal'])
            ->assertRedirect($this->url());
        $this->assertSame(1, MaintenanceRequest::query()->withoutGlobalScopes()->count());

        $this->post('/fix/'.$this->lease->repair_token, ['category' => 'water', 'description' => 'No water again'])
            ->assertRedirect('/fix/'.$this->lease->repair_token);
    }

    public function test_page_stays_open_90_days_after_the_lease_ends(): void
    {
        $this->actingAsLandlord($this->landlord);
        $this->postJson("/api/v1/leases/{$this->lease->id}/end", ['ended_on' => '2026-11-15'])->assertOk()
            ->assertJsonPath('data.portal_url', fn ($v) => str_contains($v, '/t/'));

        $this->get($this->url().'?lang=en')->assertOk()->assertSee('Your lease ended on')->assertDontSee('Overdue');

        $this->travelTo('2027-02-13 10:00'); // 90 days after
        $this->get($this->url())->assertOk();
        $this->travelTo('2027-02-14 10:00');
        $this->get($this->url())->assertNotFound();
        $this->get('/t/abcdefghij')->assertNotFound();
    }

    public function test_landlord_sends_and_resets_the_link(): void
    {
        $this->actingAsLandlord($this->landlord);
        $old = $this->lease->portal_token;

        $this->postJson("/api/v1/leases/{$this->lease->id}/portal-link/send")->assertOk();
        $this->assertStringContainsString('/t/'.$old, $this->sms->sent[0]['message']);

        $new = $this->postJson("/api/v1/leases/{$this->lease->id}/portal-link/reset")->assertOk()->json('data.portal_url');
        $this->assertStringNotContainsString($old, $new);
        $this->get('/t/'.$old)->assertNotFound();

        $other = User::factory()->create();
        $this->actingAsLandlord($other);
        $this->postJson("/api/v1/leases/{$this->lease->id}/portal-link/send")->assertNotFound();
    }

    public function test_receipts_include_the_link_when_it_fits_in_the_sms(): void
    {
        $payment = app(PaymentRecorder::class)->record($this->lease, ['amount' => 1_000_000, 'paid_at' => '2026-11-15', 'method' => 'cash']);
        app(ReceiptNotifier::class)->send($payment, 'en');
        $message = $this->sms->sent[0]['message'];
        $this->assertLessThanOrEqual(160, mb_strlen($message));
        $this->assertStringContainsString('Your account: ', $message);
        $this->assertStringContainsString('/t/', $message);

        // Amharic is longer per segment; the link is dropped rather than paying for an extra SMS.
        app(ReceiptNotifier::class)->send($payment, 'am');
        $am = $this->sms->sent[1]['message'];
        $this->assertSame(SmsMessage::segmentsFor(str_replace(__('portal.sms_suffix', ['url' => $this->lease->portalUrl()], 'am'), '', $am)), SmsMessage::segmentsFor($am));
    }
}
