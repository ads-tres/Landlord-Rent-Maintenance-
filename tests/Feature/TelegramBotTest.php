<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Property;
use App\Models\SmsMessage;
use App\Models\TelegramLink;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private const LANDLORD_CHAT = 1001;

    private const TENANT_CHAT = 2002;

    private const TENANT_TG_USER = 555;

    private User $landlord;

    private Tenant $tenant;

    private Lease $lease;

    /** @var list<array{method: string, params: array}> */
    private array $calls = [];

    private bool $blockSends = false;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-11-15 10:00');
        config(['telegram.token' => 'TEST', 'telegram.username' => 'KirayBot', 'telegram.webhook_secret' => 'shh']);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/file/botTEST/')) {
                return Http::response(str_ends_with($request->url(), '.oga') ? 'OggS-voice' : $this->jpeg(), 200);
            }
            $method = basename(parse_url($request->url(), PHP_URL_PATH));
            $this->calls[] = ['method' => $method, 'params' => $request->data()];

            return match ($method) {
                'sendMessage' => $this->blockSends
                    ? Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)
                    : Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
                'getFile' => Http::response(['ok' => true, 'result' => ['file_path' => $request->data()['file_id'] === 'VOICE' ? 'voice/v.oga' : 'photos/p.jpg']]),
                default => Http::response(['ok' => true, 'result' => true]),
            };
        });

        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian']);
        $property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 3', 'status' => 'occupied']);
        $this->tenant = Tenant::factory()->for($this->landlord, 'landlord')->create(['phone' => '+251911234567', 'full_name' => 'Almaz']);
        $this->lease = Lease::factory()->create(['landlord_id' => $this->landlord->id, 'unit_id' => $unit->id, 'tenant_id' => $this->tenant->id, 'start_date' => '2026-11-01', 'end_date' => '2028-10-31']);
        app(RentSchedule::class)->sync($this->lease);
    }

    private function jpeg(): string
    {
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }

    private function update(array $update): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => random_int(1, 1_000_000)] + $update, ['X-Telegram-Bot-Api-Secret-Token' => 'shh'])->assertOk();
    }

    private function message(int $chat, array $fields, int $from = self::TENANT_TG_USER): void
    {
        $this->update(['message' => ['message_id' => 1, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $from, 'language_code' => 'en']] + $fields]);
    }

    private function tapButton(int $chat, string $data): void
    {
        $this->update(['callback_query' => ['id' => 'cb1', 'data' => $data, 'from' => ['id' => self::TENANT_TG_USER], 'message' => ['chat' => ['id' => $chat]]]]);
    }

    private function sentTo(int $chat): array
    {
        return array_values(array_map(
            fn ($c) => $c['params']['text'],
            array_filter($this->calls, fn ($c) => $c['method'] === 'sendMessage' && (int) $c['params']['chat_id'] === $chat),
        ));
    }

    private function linkTenant(): void
    {
        $this->message(self::TENANT_CHAT, ['text' => '/start']);
        $this->message(self::TENANT_CHAT, ['contact' => ['phone_number' => '251911234567', 'user_id' => self::TENANT_TG_USER]]);
        $this->tapButton(self::TENANT_CHAT, 'consent:yes');
    }

    public function test_webhook_requires_the_secret(): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => 1])->assertForbidden();
        $this->postJson('/api/telegram/webhook', ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
    }

    public function test_landlord_links_with_a_code_from_the_app(): void
    {
        $this->actingAsLandlord($this->landlord);
        $url = $this->postJson('/api/v1/telegram/link')->assertCreated()->json('data.url');
        $this->assertStringStartsWith('https://t.me/KirayBot?start=', $url);
        $code = substr($url, strpos($url, '=') + 1);

        $this->message(self::LANDLORD_CHAT, ['text' => "/start {$code}"], from: 9);

        $this->assertStringContainsString('Connected', $this->sentTo(self::LANDLORD_CHAT)[0]);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.telegram_linked', true);

        // The code works once.
        $this->message(3003, ['text' => "/start {$code}"], from: 10);
        $this->assertStringContainsString('expired', $this->sentTo(3003)[0]);

        $this->deleteJson('/api/v1/telegram/link')->assertNoContent();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.telegram_linked', false);
    }

    public function test_tenant_links_by_sharing_their_own_contact_and_agreeing(): void
    {
        $this->message(self::TENANT_CHAT, ['text' => '/start']);
        $keyboard = collect($this->calls)->last()['params']['reply_markup']['keyboard'][0][0];
        $this->assertTrue($keyboard['request_contact']);

        // Someone else's contact is refused.
        $this->message(self::TENANT_CHAT, ['contact' => ['phone_number' => '+251911234567', 'user_id' => 999]]);
        $this->assertStringContainsString('your own phone number', collect($this->sentTo(self::TENANT_CHAT))->last());

        $this->message(self::TENANT_CHAT, ['contact' => ['phone_number' => '251911234567', 'user_id' => self::TENANT_TG_USER]]);
        $consent = collect($this->sentTo(self::TENANT_CHAT))->last();
        $this->assertStringContainsString('Room 3 · Bole Compound', $consent);
        $this->assertStringContainsString('outside Ethiopia', $consent);
        $this->assertNull(TelegramLink::activeFor($this->tenant));

        $this->tapButton(self::TENANT_CHAT, 'consent:yes');
        $this->assertNotNull(TelegramLink::activeFor($this->tenant));

        $this->actingAsLandlord($this->landlord);
        $this->getJson('/api/v1/tenants')->assertJsonPath('data.0.telegram_linked', true);
    }

    public function test_unknown_number_and_declined_consent(): void
    {
        $this->message(self::TENANT_CHAT, ['contact' => ['phone_number' => '0922000000', 'user_id' => self::TENANT_TG_USER]]);
        $this->assertStringContainsString('not registered', $this->sentTo(self::TENANT_CHAT)[0]);

        $this->message(self::TENANT_CHAT, ['contact' => ['phone_number' => '0911234567', 'user_id' => self::TENANT_TG_USER]]);
        $this->tapButton(self::TENANT_CHAT, 'consent:no');
        $this->assertSame(0, TelegramLink::query()->count());
    }

    public function test_receipts_go_to_telegram_once_linked_and_sms_otherwise(): void
    {
        $this->actingAsLandlord($this->landlord);
        $pay = fn ($ref) => $this->postJson('/api/v1/payments', ['lease_id' => $this->lease->id, 'amount' => 100_000, 'paid_at' => '2026-11-15', 'method' => 'telebirr', 'reference' => $ref])->assertCreated();

        $pay('TB1');
        $this->assertCount(1, $this->sms->sent);

        $this->linkTenant();
        $pay('TB2');

        $this->assertCount(1, $this->sms->sent); // no new SMS
        $receipt = collect($this->sentTo(self::TENANT_CHAT))->last();
        $this->assertStringContainsString('Received Br 1,000 for Room 3', $receipt);
        $this->assertStringNotContainsString('Almaz', $receipt); // no names in Telegram messages
        $this->assertDatabaseHas('sms_messages', ['channel' => 'telegram', 'kind' => 'receipt', 'status' => 'sent', 'segments' => 0]);
    }

    public function test_blocked_bot_falls_back_to_sms_and_switches_telegram_off(): void
    {
        $this->linkTenant();
        $this->blockSends = true;

        $this->actingAsLandlord($this->landlord);
        $this->postJson('/api/v1/payments', ['lease_id' => $this->lease->id, 'amount' => 100_000, 'paid_at' => '2026-11-15', 'method' => 'cash'])->assertCreated();

        $this->assertCount(1, $this->sms->sent);
        $this->assertStringContainsString('Received Br 1,000', $this->sms->sent[0]['message']);
        $this->assertNull(TelegramLink::activeFor($this->tenant));
    }

    public function test_tenant_reports_a_repair_with_a_photo_and_the_landlord_is_alerted(): void
    {
        TelegramLink::query()->create(['landlord_id' => $this->landlord->id, 'linkable_type' => $this->landlord->getMorphClass(), 'linkable_id' => $this->landlord->id, 'chat_id' => self::LANDLORD_CHAT, 'consented_at' => now()]);
        $this->linkTenant();

        $this->message(self::TENANT_CHAT, ['text' => '/repair']);
        $this->assertStringContainsString('Describe the problem', collect($this->sentTo(self::TENANT_CHAT))->last());

        $this->message(self::TENANT_CHAT, ['photo' => [['file_id' => 'SMALL'], ['file_id' => 'BIG']], 'caption' => 'Kitchen tap broken']);

        $repair = MaintenanceRequest::query()->withoutGlobalScopes()->sole();
        $this->assertSame('Kitchen tap broken', $repair->title);
        $this->assertSame('tenant', $repair->source);
        $this->assertSame($this->lease->id, $repair->lease_id);
        $this->assertSame('image/jpeg', Attachment::query()->withoutGlobalScopes()->sole()->mime);
        $this->assertTrue(collect($this->calls)->contains(fn ($c) => $c['method'] === 'getFile' && $c['params']['file_id'] === 'BIG'));

        $this->assertStringContainsString('Sent to your landlord', collect($this->sentTo(self::TENANT_CHAT))->last());
        $this->assertStringContainsString('New repair at Room 3: Kitchen tap broken', collect($this->sentTo(self::LANDLORD_CHAT))->last());

        // Status updates come back on Telegram.
        $this->actingAsLandlord($this->landlord);
        $this->patchJson("/api/v1/maintenance/{$repair->id}", ['status' => 'in_progress', 'note' => 'Plumber at 3pm'])->assertOk();
        $this->assertStringContainsString('being worked on. Plumber at 3pm', collect($this->sentTo(self::TENANT_CHAT))->last());
        $this->assertEmpty($this->sms->sent);
    }

    public function test_voice_note_repair_gets_a_signed_link(): void
    {
        $this->linkTenant();
        $this->message(self::TENANT_CHAT, ['text' => '/repair']);
        $this->message(self::TENANT_CHAT, ['voice' => ['file_id' => 'VOICE', 'duration' => 5]]);

        $repair = MaintenanceRequest::query()->withoutGlobalScopes()->sole();
        $this->assertSame('Voice note from Telegram', $repair->title);

        $this->actingAsLandlord($this->landlord);
        $url = $this->getJson("/api/v1/maintenance/{$repair->id}")
            ->assertJsonCount(0, 'data.photos')
            ->assertJsonCount(1, 'data.voice_notes')
            ->json('data.voice_notes.0.url');

        // Works without a token while valid; a tampered link does not.
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'audio/ogg');
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
    }

    public function test_repair_needs_a_link_and_text_without_command_gets_help(): void
    {
        $this->message(self::TENANT_CHAT, ['text' => '/repair']);
        $this->assertStringContainsString('/start', $this->sentTo(self::TENANT_CHAT)[0]);

        $this->linkTenant();
        $this->message(self::TENANT_CHAT, ['text' => 'hello']);
        $this->assertStringContainsString('/repair', collect($this->sentTo(self::TENANT_CHAT))->last());
        $this->assertSame(0, MaintenanceRequest::query()->withoutGlobalScopes()->count());
    }

    public function test_tenant_with_two_homes_chooses_the_unit(): void
    {
        $otherLandlord = User::factory()->create();
        $p = Property::factory()->for($otherLandlord, 'landlord')->create(['name' => 'CMC House']);
        $u = Unit::factory()->for($p)->create(['landlord_id' => $otherLandlord->id, 'label' => 'Shop 1']);
        $t = Tenant::factory()->for($otherLandlord, 'landlord')->create(['phone' => '+251911234567']);
        $other = Lease::factory()->create(['landlord_id' => $otherLandlord->id, 'unit_id' => $u->id, 'tenant_id' => $t->id]);

        $this->linkTenant();
        $this->assertSame(2, TelegramLink::query()->whereNotNull('consented_at')->count());

        $this->message(self::TENANT_CHAT, ['text' => '/repair']);
        $buttons = collect($this->calls)->last()['params']['reply_markup']['inline_keyboard'];
        $this->assertCount(2, $buttons);

        $this->tapButton(self::TENANT_CHAT, 'repair:'.$other->id);
        $this->message(self::TENANT_CHAT, ['text' => 'Door lock broken']);

        $repair = MaintenanceRequest::query()->withoutGlobalScopes()->sole();
        $this->assertSame($otherLandlord->id, $repair->landlord_id);
        $this->assertSame($u->id, $repair->unit_id);
    }

    public function test_cannot_pick_someone_elses_lease(): void
    {
        $stranger = Lease::factory()->create();
        $this->linkTenant();

        $this->tapButton(self::TENANT_CHAT, 'repair:'.$stranger->id);
        $this->message(self::TENANT_CHAT, ['text' => 'Sneaky']);

        $this->assertSame(0, MaintenanceRequest::query()->withoutGlobalScopes()->count());
    }

    public function test_stop_returns_to_sms(): void
    {
        $this->linkTenant();
        $this->message(self::TENANT_CHAT, ['text' => '/stop']);

        $this->assertSame(0, TelegramLink::query()->count());
        $this->assertSame(0, SmsMessage::query()->where('channel', 'telegram')->count());
    }

    public function test_link_code_needs_telegram_configured(): void
    {
        config(['telegram.token' => null]);
        $this->actingAsLandlord($this->landlord);

        $this->postJson('/api/v1/telegram/link')->assertStatus(503);
    }

    public function test_tenant_pages_link_to_the_bot(): void
    {
        $this->get('/fix/'.$this->lease->repair_token.'?lang=en')->assertSee('https://t.me/KirayBot?start=tenant', false);
    }
}
