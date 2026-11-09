<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Property;
use App\Models\SmsMessage;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payments\PaymentRecorder;
use App\Services\Rent\RentSchedule;
use App\Services\Sms\SmsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

class AccountDataTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00');
        $this->landlord = User::factory()->create(['name' => 'Abel', 'phone' => '+251911000001', 'locale' => 'en']);
        $property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 1', 'status' => 'occupied']);
        $lease = Lease::factory()->create(['landlord_id' => $this->landlord->id, 'unit_id' => $unit->id, 'start_date' => '2026-11-01', 'end_date' => '2028-10-31', 'monthly_rent' => 1_000_000]);
        $lease->tenant->update(['full_name' => 'አልማዝ ተስፋዬ']);
        app(RentSchedule::class)->sync($lease);
        app(PaymentRecorder::class)->record($lease, ['amount' => 1_000_000, 'paid_at' => '2026-11-02', 'method' => 'telebirr', 'reference' => 'TB1']);
        $this->actingAsLandlord($this->landlord);
    }

    public function test_landlord_downloads_all_their_data_as_csv_files(): void
    {
        $response = $this->get('/api/v1/account/export')->assertOk();
        $this->assertSame('application/zip', $response->headers->get('content-type'));

        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->all();

        $this->assertContains('payments.csv', $names);
        $this->assertContains('tenants.csv', $names);
        $this->assertContains('rent_changes.csv', $names);
        $this->assertContains('README.txt', $names);
        $payments = $zip->getFromName('payments.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $payments);
        $this->assertStringContainsString('10000.00,telebirr,TB1', $payments);
        $this->assertStringContainsString('አልማዝ ተስፋዬ', $zip->getFromName('tenants.csv'));
        $zip->close();
    }

    public function test_deletion_waits_30_days_and_can_be_cancelled(): void
    {
        $this->postJson('/api/v1/account/deletion', ['phone' => '0922000000'])->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->postJson('/api/v1/account/deletion', ['phone' => '0911000001'])->assertOk()
            ->assertJsonPath('data.deletion_scheduled_for', '2026-12-15');
        $this->deleteJson('/api/v1/account/deletion')->assertOk()->assertJsonPath('data.deletion_scheduled_for', null);

        $this->postJson('/api/v1/account/deletion', ['phone' => '0911000001'])->assertOk();
        $this->travelTo('2026-12-14 10:00');
        $this->artisan('accounts:purge')->expectsOutput('Deleted 0 account(s).');
        $this->assertDatabaseHas('users', ['id' => $this->landlord->id]);
    }

    public function test_after_30_days_the_account_and_its_data_are_deleted(): void
    {
        $caretaker = User::factory()->create(['role' => User::ROLE_CARETAKER]);
        $caretaker->forceFill(['landlord_id' => $this->landlord->id])->save();
        $other = User::factory()->create();
        Property::factory()->for($other, 'landlord')->create();
        app(SmsNotifier::class)->queue($this->landlord->id, '+251922000002', 'receipt', 'Hi');

        $this->postJson('/api/v1/account/deletion', ['phone' => '+251911000001'])->assertOk();
        $this->travelTo('2026-12-16 10:00');
        $this->artisan('accounts:purge')->expectsOutput('Deleted 1 account(s).');

        $this->assertDatabaseMissing('users', ['id' => $this->landlord->id]);
        $this->assertDatabaseMissing('users', ['id' => $caretaker->id]);
        foreach (['properties', 'units', 'tenants', 'leases', 'charges', 'payments'] as $table) {
            $this->assertSame(0, DB::table($table)->where('landlord_id', $this->landlord->id)->count(), $table);
        }
        $this->assertSame(0, SmsMessage::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, Property::query()->withoutGlobalScopes()->count()); // the other landlord's
    }

    public function test_caretakers_cannot_export_or_delete(): void
    {
        $caretaker = User::factory()->create(['role' => User::ROLE_CARETAKER]);
        $caretaker->forceFill(['landlord_id' => $this->landlord->id])->save();
        $this->actingAsLandlord($caretaker);
        $this->get('/api/v1/account/export', ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson('/api/v1/account/deletion', ['phone' => '0911000001'])->assertForbidden();
    }
}
