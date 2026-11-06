<?php

namespace Tests\Feature;

use App\Http\Middleware\CaretakerLimits;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\TelegramLink;
use App\Models\Unit;
use App\Models\User;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class CaretakerTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Property $property;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00');
        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian', 'name' => 'Abel', 'phone' => '+251911000001']);
        $this->property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);
        $unit = Unit::factory()->for($this->property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 1', 'status' => 'occupied']);
        $this->lease = Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $unit->id,
            'start_date' => '2026-11-01', 'end_date' => '2028-10-31', 'monthly_rent' => 1_000_000, 'due_day' => 1,
        ]);
        $this->lease->tenant->update(['full_name' => 'Almaz Tesfaye']);
        app(RentSchedule::class)->sync($this->lease);
    }

    private function invite(string $phone = '0922000002', string $name = 'Bekele'): array
    {
        $this->actingAsLandlord($this->landlord);

        return $this->postJson('/api/v1/team', ['phone' => $phone, 'name' => $name])->assertCreated()->json('data');
    }

    private function login(string $phone): string
    {
        $this->app['auth']->forgetGuards();
        $this->travel(2)->minutes(); // one code per minute per phone
        $this->postJson('/api/v1/auth/otp', ['phone' => $phone])->assertAccepted();

        return $this->postJson('/api/v1/auth/verify', ['phone' => $phone, 'code' => $this->sms->lastCodeFor($phone)])
            ->assertOk()->json('token');
    }

    public function test_caretaker_is_invited_logs_in_and_works_on_the_landlords_data(): void
    {
        $member = $this->invite();
        $this->assertStringContainsString('Abel added you as a caretaker', $this->sms->sent[0]['message']);
        $this->assertFalse($member['joined']);

        $token = $this->login('+251922000002');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.role', 'caretaker')
            ->assertJsonPath('data.landlord_name', 'Abel');
        $this->withToken($token)->getJson('/api/v1/properties')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Bole Compound');
        $this->withToken($token)->getJson("/api/v1/leases/{$this->lease->id}")->assertOk();

        // Another landlord's data stays out of reach.
        $other = User::factory()->create();
        $otherProperty = Property::factory()->for($other, 'landlord')->create();
        $this->withToken($token)->getJson("/api/v1/properties/{$otherProperty->id}")->assertNotFound();

        $this->actingAsLandlord($this->landlord);
        $this->getJson('/api/v1/team')->assertJsonCount(1, 'data')->assertJsonPath('data.0.joined', true);
    }

    public function test_payments_and_repairs_show_who_handled_them_and_the_landlord_is_told(): void
    {
        config(['telegram.token' => 'TEST']);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        TelegramLink::query()->create(['landlord_id' => $this->landlord->id, 'linkable_type' => $this->landlord->getMorphClass(), 'linkable_id' => $this->landlord->id, 'chat_id' => 5, 'consented_at' => now()]);

        $this->invite();
        $caretaker = User::query()->where('phone', '+251922000002')->sole();
        $this->actingAsLandlord($caretaker);

        $this->postJson('/api/v1/payments', [
            'lease_id' => $this->lease->id, 'amount' => 1_000_000, 'paid_at' => '2026-11-15', 'method' => 'cash', 'send_sms' => false,
        ])->assertCreated()->assertJsonPath('data.recorded_by_name', 'Bekele');
        $this->assertSame($caretaker->id, Payment::query()->withoutGlobalScopes()->value('recorded_by'));
        Http::assertSent(fn ($r) => str_contains((string) $r['text'], 'Bekele logged Br 10,000 from Almaz Tesfaye (Room 1).'));

        $this->post('/fix/'.$this->lease->repair_token, ['category' => 'water', 'description' => 'No water']);
        $this->actingAsLandlord($caretaker);
        $id = MaintenanceRequest::query()->withoutGlobalScopes()->value('id');
        $this->patchJson("/api/v1/maintenance/{$id}", ['status' => 'in_progress', 'notify_tenant' => false])
            ->assertOk()->assertJsonPath('data.handled_by_name', 'Bekele');

        // The landlord's own actions aren't labelled.
        $this->actingAsLandlord($this->landlord);
        $this->patchJson("/api/v1/maintenance/{$id}", ['status' => 'done', 'notify_tenant' => false])
            ->assertOk()->assertJsonPath('data.handled_by_name', null);
    }

    public function test_landlord_only_actions_are_refused(): void
    {
        $this->invite();
        $caretaker = User::query()->where('phone', '+251922000002')->sole();
        $payment = Payment::query()->forceCreate([
            'landlord_id' => $this->landlord->id, 'lease_id' => $this->lease->id, 'tenant_id' => $this->lease->tenant_id,
            'amount' => 100, 'paid_at' => '2026-11-15', 'method' => 'cash', 'receipt_token' => Str::lower(Str::random(10)),
        ]);
        $this->actingAsLandlord($caretaker);

        $this->getJson('/api/v1/reports/quarterly')->assertForbidden()->assertJsonPath('message', 'Only the landlord can do this.');
        $this->getJson('/api/v1/expenses')->assertForbidden();
        $this->getJson('/api/v1/payout-account')->assertForbidden();
        $this->getJson('/api/v1/team')->assertForbidden();
        $this->postJson('/api/v1/properties', ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/properties/{$this->property->id}")->assertForbidden();
        $this->postJson("/api/v1/leases/{$this->lease->id}/end", ['ended_on' => '2026-11-30'])->assertForbidden();
        $this->deleteJson("/api/v1/payments/{$payment->id}")->assertForbidden();
        $this->getJson("/api/v1/tenants/{$this->lease->tenant_id}/export")->assertForbidden();

        // Rent reminders and online rent are the landlord's settings.
        $this->patchJson('/api/v1/auth/me', ['sms_reminders' => false, 'name' => 'Bekele T.'])->assertOk();
        $this->assertTrue((bool) $this->landlord->refresh()->sms_reminders);
        $this->assertSame('Bekele T.', $caretaker->refresh()->name);
    }

    public function test_every_landlord_only_entry_matches_a_route(): void
    {
        $actions = collect(Route::getRoutes()->getRoutes())->map->getActionName();
        foreach (CaretakerLimits::LANDLORD_ONLY as $entry) {
            [$controller, $method] = explode('@', $entry);
            $this->assertTrue(
                $actions->contains(fn ($a) => $method === '*' ? str_starts_with($a, $controller.'@') : $a === $entry),
                "No route for {$entry}",
            );
        }
    }

    public function test_removing_a_caretaker_signs_them_out_and_blocks_login(): void
    {
        $member = $this->invite();
        $token = $this->login('+251922000002');

        $this->actingAsLandlord($this->landlord);
        $this->deleteJson("/api/v1/team/{$member['id']}")->assertNoContent();
        $this->getJson('/api/v1/team')->assertJsonCount(0, 'data');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/properties')->assertUnauthorized();

        $this->travel(2)->minutes();
        $this->postJson('/api/v1/auth/otp', ['phone' => '+251922000002'])->assertAccepted();
        $this->postJson('/api/v1/auth/verify', ['phone' => '+251922000002', 'code' => $this->sms->lastCodeFor('+251922000002')])
            ->assertForbidden();

        // Invited again, they can log in.
        $this->invite();
        $this->login('+251922000002');
    }

    public function test_invites_are_checked(): void
    {
        $this->actingAsLandlord($this->landlord);
        $this->postJson('/api/v1/team', ['phone' => '0911000001', 'name' => 'Me'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/api/v1/team', ['phone' => '123', 'name' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('phone');

        $otherLandlord = User::factory()->create(['phone' => '+251933000003']);
        $this->postJson('/api/v1/team', ['phone' => '0933000003', 'name' => 'Landlord'])->assertUnprocessable()->assertJsonValidationErrors('phone');

        // Someone else's caretaker can't be taken over.
        $this->invite('0944000004', 'Kebede');
        $this->actingAsLandlord($otherLandlord);
        $this->postJson('/api/v1/team', ['phone' => '0944000004', 'name' => 'Kebede'])->assertUnprocessable();

        $this->actingAsLandlord($this->landlord);
        foreach (['0955000005', '0955000006', '0955000007', '0955000008'] as $i => $phone) {
            $this->postJson('/api/v1/team', ['phone' => $phone, 'name' => "C{$i}"])->assertCreated();
        }
        $this->postJson('/api/v1/team', ['phone' => '0955000009', 'name' => 'Sixth'])->assertUnprocessable();
    }
}
