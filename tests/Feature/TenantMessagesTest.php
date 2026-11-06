<?php

namespace Tests\Feature;

use App\Jobs\SendSms;
use App\Models\Lease;
use App\Models\Property;
use App\Models\SmsMessage;
use App\Models\TelegramLink;
use App\Models\Unit;
use App\Models\User;
use App\Services\Sms\SmsNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TenantMessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00', SmsNotifier::TIMEZONE));
        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian', 'sms_reminders' => true]);
        $property = Property::factory()->for($this->landlord, 'landlord')->create();
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 1', 'status' => 'occupied']);
        $this->lease = Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $unit->id,
            'start_date' => '2026-11-01', 'end_date' => '2028-10-31', 'monthly_rent' => 1_000_000, 'due_day' => 1,
        ]);
        $this->actingAsLandlord($this->landlord);
    }

    public function test_tenant_who_opted_out_gets_no_rent_reminders(): void
    {
        $tenant = $this->lease->tenant;
        $this->patchJson("/api/v1/tenants/{$tenant->id}", ['reminders_opt_out' => true])
            ->assertOk()->assertJsonPath('data.reminders_opt_out', true);

        $this->artisan('rent:generate-charges');
        $this->artisan('rent:send-reminders');
        $this->assertSame(0, SmsMessage::query()->withoutGlobalScopes()->where('kind', 'like', 'reminder%')->count());

        $tenant->refresh()->update(['reminders_opt_out' => false]);
        $this->artisan('rent:send-reminders');
        $this->assertSame(1, SmsMessage::query()->withoutGlobalScopes()->where('kind', 'like', 'reminder%')->count());
    }

    public function test_messages_at_night_wait_until_seven_in_the_morning(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-28 22:30', SmsNotifier::TIMEZONE));

        $message = app(SmsNotifier::class)->toTenant($this->lease->tenant, 'receipt', 'Received Br 10,000');

        $this->assertSame('2026-11-29 07:00', $message->send_after->setTimezone(SmsNotifier::TIMEZONE)->format('Y-m-d H:i'));
        Queue::assertPushed(SendSms::class, fn ($job) => $job->delay !== null);

        // Early morning waits for the same day's 07:00; daytime goes straight out.
        $this->travelTo(CarbonImmutable::parse('2026-11-29 05:00', SmsNotifier::TIMEZONE));
        $this->assertSame('2026-11-29 07:00', SmsNotifier::quietUntil()->format('Y-m-d H:i'));
        $this->travelTo(CarbonImmutable::parse('2026-11-29 12:00', SmsNotifier::TIMEZONE));
        $this->assertNull(SmsNotifier::quietUntil());
    }

    public function test_message_log_shows_sms_and_telegram_for_this_tenant_only(): void
    {
        $tenant = $this->lease->tenant;
        $sms = app(SmsNotifier::class);
        $sms->toTenant($tenant, 'receipt', 'Received Br 10,000');

        config(['telegram.token' => 'TEST']);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        TelegramLink::query()->create(['landlord_id' => $this->landlord->id, 'linkable_type' => $tenant->getMorphClass(), 'linkable_id' => $tenant->id, 'chat_id' => 77, 'consented_at' => now()]);
        $sms->toTenant($tenant, 'portal_link', 'Your page');

        $other = Lease::factory()->create(['landlord_id' => $this->landlord->id, 'unit_id' => $this->lease->unit_id]);
        $sms->toTenant($other->tenant, 'receipt', 'Someone else');

        $this->getJson("/api/v1/tenants/{$tenant->id}/messages")->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.channel', 'telegram')
            ->assertJsonPath('data.0.kind', 'portal_link')
            ->assertJsonPath('data.1.channel', 'sms')
            ->assertJsonPath('data.1.status', 'sent')
            ->assertJsonPath('data.1.body', 'Received Br 10,000');

        $this->actingAsLandlord(User::factory()->create());
        $this->getJson("/api/v1/tenants/{$tenant->id}/messages")->assertNotFound();
    }
}
