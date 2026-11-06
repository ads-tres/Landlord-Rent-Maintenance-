<?php

namespace Tests\Feature;

use App\Models\ChapaTransaction;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PayoutAccount;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChapaOnlineRentTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Lease $lease;

    /** What Chapa's verify endpoint answers, by tx_ref. */
    private array $verify = [];

    private array $initialized = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00');
        config(['chapa.secret_key' => 'CHASECK_TEST', 'chapa.webhook_secret' => 'whsec', 'chapa.platform_fee_santim' => 2500]);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/banks') => Http::response(['data' => [['id' => '946', 'name' => 'Commercial Bank of Ethiopia'], ['id' => '855', 'name' => 'Telebirr']]]),
                str_ends_with($path, '/subaccount') => $request['account_number'] === '000000'
                    ? Http::response(['message' => 'Invalid account'], 400)
                    : Http::response(['status' => 'success', 'data' => ['subaccount_id' => 'SUB-123']]),
                str_ends_with($path, '/transaction/initialize') => (function () use ($request) {
                    $this->initialized[] = $request->data();

                    return Http::response(['status' => 'success', 'data' => ['checkout_url' => 'https://checkout.chapa.co/checkout/payment/'.$request['tx_ref']]]);
                })(),
                str_contains($path, '/transaction/verify/') => Http::response(['status' => 'success', 'data' => $this->verify[basename($path)] ?? ['status' => 'pending']]),
                default => Http::response(['ok' => true]),
            };
        });

        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian', 'name' => 'Abel']);
        $property = Property::factory()->for($this->landlord, 'landlord')->create();
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 3', 'status' => 'occupied']);
        $this->lease = Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $unit->id,
            'start_date' => '2026-10-01', 'end_date' => '2028-09-30', 'monthly_rent' => 1_000_000, 'due_day' => 1,
        ]);
        app(RentSchedule::class)->sync($this->lease);
    }

    private function enableOnlineRent(): void
    {
        $this->actingAsLandlord($this->landlord);
        $this->putJson('/api/v1/payout-account', ['bank_code' => '946', 'bank_name' => 'CBE', 'account_name' => 'Abel Kebede', 'account_number' => '1000123456789'])
            ->assertOk()->assertJsonPath('data.status', 'active');
        $this->patchJson('/api/v1/auth/me', ['online_rent_enabled' => true])->assertOk()->assertJsonPath('data.online_rent_enabled', true);
    }

    private function webhook(array $payload, ?string $secret = 'whsec'): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/chapa/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CHAPA_SIGNATURE' => $secret ? hash_hmac('sha256', $body, $secret) : 'bad',
        ], $body);
    }

    /** Tenant opens the pay page and starts a payment; returns the tx_ref. */
    private function startPayment(int $amount): string
    {
        $token = $this->lease->fresh()->pay_token;
        $this->post("/pay/{$token}", ['amount' => $amount])->assertRedirect();

        return ChapaTransaction::query()->withoutGlobalScopes()->latest('id')->value('tx_ref');
    }

    public function test_payout_account_setup_and_switch(): void
    {
        $this->actingAsLandlord($this->landlord);
        $this->getJson('/api/v1/chapa/banks')->assertOk()->assertJsonPath('data.0.name', 'Commercial Bank of Ethiopia');

        $this->patchJson('/api/v1/auth/me', ['online_rent_enabled' => true])->assertUnprocessable();

        $this->putJson('/api/v1/payout-account', ['bank_code' => '946', 'account_name' => 'Abel', 'account_number' => '000000'])
            ->assertUnprocessable()->assertJsonPath('data.status', 'failed');

        $this->enableOnlineRent();
        $this->getJson('/api/v1/payout-account')->assertJsonPath('data.account_number', '•••••••••6789');
        $this->assertSame('1000123456789', PayoutAccount::query()->value('account_number')); // decrypted
        $this->assertNotSame('1000123456789', \DB::table('payout_accounts')->value('account_number')); // encrypted at rest
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/subaccount') && $r['split_type'] === 'flat' && (float) $r['split_value'] === 25.0);
    }

    public function test_pay_page_is_hidden_until_online_rent_is_on(): void
    {
        $this->get('/pay/'.$this->lease->pay_token)->assertNotFound();

        $this->enableOnlineRent();
        $this->getJson("/api/v1/leases/{$this->lease->id}")->assertJsonPath('data.pay_url', url('/pay/'.$this->lease->pay_token));
        $this->get('/pay/'.$this->lease->pay_token.'?lang=en')->assertOk()->assertSee('Br 20,000')->assertSee('Pay one month');
    }

    public function test_full_flow_webhook_verifies_and_records_once(): void
    {
        $this->enableOnlineRent();
        $txRef = $this->startPayment(2_000_000);

        $init = $this->initialized[0];
        $this->assertSame('20000.00', $init['amount']);
        $this->assertSame('ETB', $init['currency']);
        $this->assertSame('SUB-123', $init['subaccounts']['id']);
        $this->assertSame($txRef, $init['tx_ref']);
        $this->assertStringContainsString('/pay/'.$this->lease->pay_token.'/done', $init['return_url']);

        // Bad signature is refused before anything is checked.
        $this->webhook(['tx_ref' => $txRef, 'status' => 'success'], 'wrong')->assertForbidden();

        // Valid webhook, but Chapa still says pending: nothing recorded.
        $this->webhook(['tx_ref' => $txRef, 'status' => 'success'])->assertOk();
        $this->assertSame(0, Payment::query()->withoutGlobalScopes()->count());

        // Chapa confirms; webhook and return page both arrive.
        $this->verify[$txRef] = ['status' => 'success', 'amount' => '20000.00', 'currency' => 'ETB', 'reference' => 'APxyz'];
        $this->webhook(['tx_ref' => $txRef, 'status' => 'success'])->assertOk();
        $this->get('/pay/'.$this->lease->pay_token.'/done?tx_ref='.$txRef.'&lang=en')->assertOk()->assertSee('Payment received');
        $this->webhook(['tx_ref' => $txRef, 'status' => 'success'])->assertOk();

        $payment = Payment::query()->withoutGlobalScopes()->sole();
        $this->assertSame('chapa', $payment->source);
        $this->assertSame('chapa', $payment->method);
        $this->assertSame('APxyz', $payment->reference);
        $this->assertSame(2_000_000, $payment->amount);
        $this->assertSame(['2026-10' => 'paid', '2026-11' => 'paid'], Charge::query()->withoutGlobalScopes()->orderBy('period')->pluck('status', 'period')->all());
        $this->assertCount(1, collect($this->sms->sent)->filter(fn ($s) => str_contains($s['message'], 'Received Br 20,000')));

        // Online payments can't be removed by hand.
        $this->deleteJson("/api/v1/payments/{$payment->id}")->assertStatus(409);
    }

    public function test_amount_mismatch_or_failure_records_nothing(): void
    {
        $this->enableOnlineRent();
        $a = $this->startPayment(1_000_000);
        $b = $this->startPayment(1_000_000);

        $this->verify[$a] = ['status' => 'success', 'amount' => '10.00', 'currency' => 'ETB'];
        $this->verify[$b] = ['status' => 'failed', 'amount' => '10000.00', 'currency' => 'ETB'];
        $this->webhook(['tx_ref' => $a])->assertOk();
        $this->webhook(['tx_ref' => $b])->assertOk();

        $this->assertSame(0, Payment::query()->withoutGlobalScopes()->count());
        $this->assertSame(['failed', 'failed'], ChapaTransaction::query()->withoutGlobalScopes()->orderBy('id')->pluck('status')->all());
        $this->get('/pay/'.$this->lease->pay_token.'/done?tx_ref='.$b.'&lang=en')->assertSee('did not go through');
    }

    public function test_reconcile_catches_a_missed_webhook(): void
    {
        $this->enableOnlineRent();
        $txRef = $this->startPayment(1_000_000);
        $this->verify[$txRef] = ['status' => 'success', 'amount' => '10000.00', 'currency' => 'ETB', 'reference' => 'APabc'];

        $this->artisan('chapa:reconcile')->expectsOutput('Confirmed 0 payment(s).'); // too recent
        $this->travel(11)->minutes();
        $this->artisan('chapa:reconcile')->expectsOutput('Confirmed 1 payment(s).');
        $this->assertSame(1, Payment::query()->withoutGlobalScopes()->count());
    }

    public function test_amount_limits_and_lease_state(): void
    {
        $this->enableOnlineRent();
        $token = $this->lease->pay_token;

        $this->post("/pay/{$token}", ['amount' => 50])->assertSessionHasErrors('amount');
        $this->post("/pay/{$token}", ['amount' => 30_000_000])->assertSessionHasErrors('amount'); // more than the lease owes

        $this->postJson("/api/v1/leases/{$this->lease->id}/end", ['ended_on' => '2026-11-15'])->assertOk();
        $this->get("/pay/{$token}")->assertNotFound();
    }

    public function test_pay_link_is_sent_and_added_to_reminders(): void
    {
        $this->enableOnlineRent();
        $this->postJson("/api/v1/leases/{$this->lease->id}/pay-link/send")->assertOk();
        $this->assertStringContainsString('Pay rent for Room 3 online', end($this->sms->sent)['message']);
        $this->assertStringContainsString('/pay/'.$this->lease->pay_token, end($this->sms->sent)['message']);

        $this->travelTo('2026-11-28 10:00'); // December rent due on the 1st, 3 days ahead
        $this->artisan('rent:generate-charges');
        $this->travelTo('2026-11-28 10:05');
        $this->artisan('rent:send-reminders');
        $this->assertStringContainsString('Pay online: '.url('/pay/'.$this->lease->pay_token), end($this->sms->sent)['message']);
    }

    public function test_another_landlord_cannot_use_these(): void
    {
        $this->enableOnlineRent();
        $this->actingAsLandlord();

        $this->getJson('/api/v1/payout-account')->assertJsonPath('data', null);
        $this->postJson("/api/v1/leases/{$this->lease->id}/pay-link/send")->assertNotFound();
    }

    public function test_webhook_without_chapa_configured_is_refused(): void
    {
        config(['chapa.webhook_secret' => null]);
        $this->webhook(['tx_ref' => 'x'])->assertForbidden();
    }
}
