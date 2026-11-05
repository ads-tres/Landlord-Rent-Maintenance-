<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtpAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_landlord_can_log_in_with_an_sms_code(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => '0911 234 567', 'locale' => 'en'])
            ->assertAccepted()
            ->assertJsonPath('expires_in', 300);

        $code = $this->sms->lastCodeFor('+251911234567');
        $this->assertNotNull($code);

        $response = $this->postJson('/api/v1/auth/verify', ['phone' => '0911234567', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('user.phone', '+251911234567')
            ->assertJsonPath('is_new', true);

        $token = $response->json('token');
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer $token"])
            ->assertOk()
            ->assertJsonPath('data.phone', '+251911234567');

        $this->assertNotNull(User::query()->where('phone', '+251911234567')->value('phone_verified_at'));
    }

    public function test_code_can_only_be_used_once(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => '0911234567'])->assertAccepted();
        $code = $this->sms->lastCodeFor('+251911234567');

        $this->postJson('/api/v1/auth/verify', ['phone' => '0911234567', 'code' => $code])->assertOk();
        $this->postJson('/api/v1/auth/verify', ['phone' => '0911234567', 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_wrong_code_is_rejected_and_locks_after_max_attempts(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => '0911234567'])->assertAccepted();
        $code = $this->sms->lastCodeFor('+251911234567');
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/verify', ['phone' => '0911234567', 'code' => $wrong])->assertUnprocessable();
        }

        // Even the right code fails once attempts are used up.
        $this->postJson('/api/v1/auth/verify', ['phone' => '0911234567', 'code' => $code])->assertUnprocessable();
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => '0911234567'])->assertAccepted();
        $code = $this->sms->lastCodeFor('+251911234567');

        $this->travel(6)->minutes();

        $this->postJson('/api/v1/auth/verify', ['phone' => '0911234567', 'code' => $code])->assertUnprocessable();
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => '12345'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertEmpty($this->sms->sent);
    }

    public function test_otp_requests_are_rate_limited_per_phone(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => '0911234567'])->assertAccepted();
        $this->postJson('/api/v1/auth/otp', ['phone' => '0911234567'])->assertTooManyRequests();
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer $token"])->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_protected_routes_require_a_token(): void
    {
        $this->getJson('/api/v1/properties')->assertUnauthorized();
    }

    public function test_accept_language_header_wins_over_saved_preference(): void
    {
        $this->actingAsLandlord(User::factory()->create(['locale' => 'am']));

        $this->postJson('/api/v1/properties', [], ['Accept-Language' => 'en'])
            ->assertJsonPath('errors.name.0', 'The name field is required.');

        // No usable header: fall back to the saved preference.
        $this->postJson('/api/v1/properties', [], ['Accept-Language' => ''])
            ->assertJsonPath('errors.name.0', fn (string $m) => str_contains($m, 'ያስፈልጋል'));
    }
}
