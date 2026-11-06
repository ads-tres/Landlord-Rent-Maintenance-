<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Lease $lease;

    private string $credentials;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00');
        $this->landlord = User::factory()->create(['locale' => 'en', 'name' => 'Abel']);
        $property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 1', 'status' => 'occupied']);
        $this->lease = Lease::factory()->create(['landlord_id' => $this->landlord->id, 'unit_id' => $unit->id]);

        // A throwaway service account, like the JSON from the Firebase console.
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->credentials = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($this->credentials, json_encode([
            'type' => 'service_account', 'project_id' => 'kiray-test', 'client_email' => 'push@kiray-test.iam.gserviceaccount.com', 'private_key' => $pem,
        ]));
        config(['push.credentials' => $this->credentials]);
    }

    protected function tearDown(): void
    {
        @unlink($this->credentials);
        parent::tearDown();
    }

    private function fakeFcm(int $status = 200, array $body = ['name' => 'projects/kiray-test/messages/1']): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response($body, $status),
        ]);
    }

    private function register(string $token = 'phone-token-1'): void
    {
        $this->actingAsLandlord($this->landlord);
        $this->postJson('/api/v1/devices', ['token' => $token, 'platform' => 'android'])->assertNoContent();
    }

    public function test_new_tenant_repair_pushes_to_the_landlords_phone(): void
    {
        $this->fakeFcm();
        $this->register();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.push_available', true)->assertJsonPath('data.push_enabled', true);

        $this->post('/fix/'.$this->lease->repair_token, ['category' => 'water', 'description' => 'No water since morning']);
        $repair = MaintenanceRequest::query()->withoutGlobalScopes()->sole();

        Http::assertSent(function (Request $r) use ($repair) {
            if (! str_contains($r->url(), 'fcm.googleapis.com/v1/projects/kiray-test/messages:send')) {
                return false;
            }

            return $r->hasHeader('Authorization', 'Bearer ya29.test')
                && $r['message']['token'] === 'phone-token-1'
                && str_contains($r['message']['notification']['body'], 'Room 1')
                && $r['message']['data']['route'] === "/repairs/{$repair->id}"
                && $r['message']['data']['kind'] === 'new_repair';
        });
        // The service account JWT was exchanged for an access token.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/token') && count(explode('.', $r['assertion'])) === 3);
    }

    public function test_switched_off_or_not_configured_sends_nothing(): void
    {
        $this->fakeFcm();
        $this->register();
        $this->patchJson('/api/v1/auth/me', ['push_enabled' => false])->assertOk()->assertJsonPath('data.push_enabled', false);
        $this->post('/fix/'.$this->lease->repair_token, ['category' => 'water', 'description' => 'No water']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com'));

        $this->landlord->update(['push_enabled' => true]);
        config(['push.credentials' => null]);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.push_available', false);
        $this->post('/fix/'.$this->lease->repair_token, ['category' => 'electric', 'description' => 'No power']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com'));
    }

    public function test_uninstalled_phones_are_forgotten(): void
    {
        $this->fakeFcm(404, ['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]]);
        $this->register();
        $this->post('/fix/'.$this->lease->repair_token, ['category' => 'water', 'description' => 'No water']);

        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_tokens_follow_the_account_that_logs_in_and_are_removed_on_logout(): void
    {
        $this->register('shared-phone');
        $other = User::factory()->create();
        $this->actingAsLandlord($other);
        $this->postJson('/api/v1/devices', ['token' => 'shared-phone'])->assertNoContent();
        $this->assertSame($other->id, DeviceToken::query()->sole()->user_id);

        $this->deleteJson('/api/v1/devices', ['token' => 'shared-phone'])->assertNoContent();
        $this->assertSame(0, DeviceToken::query()->count());
    }
}
