<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\WaitlistSignup;
use App\Services\Admin\OnboardingImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00');
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'phone' => '+251900000001']);
    }

    private function csv(array $rows): UploadedFile
    {
        $lines = [implode(',', OnboardingImport::COLUMNS)];
        foreach ($rows as $row) {
            // Quote every value, as spreadsheet apps do for values with commas.
            $lines[] = implode(',', array_map(fn ($c) => '"'.($row[$c] ?? '').'"', OnboardingImport::COLUMNS));
        }

        return UploadedFile::fake()->createWithContent('notebook.csv', implode("\n", $lines));
    }

    public function test_admin_logs_in_with_an_sms_code(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');

        $this->post('/admin/login', ['phone' => '0900000001'])->assertRedirect('/admin/login/code');
        $code = $this->sms->lastCodeFor('+251900000001');

        $this->post('/admin/login/code', ['phone' => '+251900000001', 'code' => $code])->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertSee('Pilot admin');
    }

    public function test_landlords_cannot_log_in_to_admin_and_no_code_is_sent(): void
    {
        $landlord = User::factory()->create(['phone' => '+251911111111']);

        $this->post('/admin/login', ['phone' => '0911111111'])->assertRedirect('/admin/login/code');
        $this->assertEmpty($this->sms->sent);

        $this->actingAs($landlord)->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_cannot_see_landlord_data_through_the_api(): void
    {
        $landlord = User::factory()->create();
        Property::factory()->for($landlord, 'landlord')->create();

        $this->actingAsLandlord($this->admin);
        $this->getJson('/api/v1/properties')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_dashboard_shows_pilot_metrics(): void
    {
        $landlord = User::factory()->create(['name' => 'Abel', 'is_pilot' => true]);
        $property = Property::factory()->for($landlord, 'landlord')->create();
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $landlord->id, 'status' => 'occupied']);
        $lease = Lease::factory()->create(['landlord_id' => $landlord->id, 'unit_id' => $unit->id, 'start_date' => '2026-11-01', 'end_date' => '2028-10-31', 'due_day' => 5]);
        $this->artisan('rent:generate-charges');

        // Paid on the 4th, before the 5th: on time.
        $payment = new Payment(['tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'amount' => 1_000_000, 'paid_at' => '2026-11-04', 'method' => 'cash', 'source' => 'manual']);
        $payment->landlord_id = $landlord->id;
        $payment->save();
        $charge = Charge::query()->withoutGlobalScopes()->first();
        DB::table('payment_allocations')->insert(['landlord_id' => $landlord->id, 'payment_id' => $payment->id, 'charge_id' => $charge->id, 'amount' => 1_000_000, 'created_at' => now(), 'updated_at' => now()]);
        $charge->forceFill(['status' => 'paid'])->save();

        // The landlord used the app this week.
        $this->actingAsLandlord($landlord);
        $this->getJson('/api/v1/dashboard')->assertOk();

        $this->actingAs($this->admin, 'web')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Abel')
            ->assertSeeInOrder(['100%', 'Rent paid on time']);

        $this->assertDatabaseHas('landlord_activity_days', ['landlord_id' => $landlord->id, 'day' => '2026-11-15']);
    }

    public function test_waitlist_filter_export_and_make_pilot(): void
    {
        $big = WaitlistSignup::query()->create(['phone' => '+251922000001', 'name' => 'Big', 'unit_count' => 8, 'locale' => 'am']);
        WaitlistSignup::query()->create(['phone' => '+251922000002', 'name' => 'Small', 'unit_count' => 1, 'locale' => 'am']);
        $this->actingAs($this->admin, 'web');

        $this->get('/admin/waitlist')->assertOk()->assertSee('Big')->assertDontSee('Small');
        $this->get('/admin/waitlist?min_units=1')->assertSee('Small');

        $csv = $this->get('/admin/waitlist/export?min_units=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('+251922000001', $csv);

        $this->patch("/admin/waitlist/{$big->id}", ['status' => 'contacted', 'admin_notes' => 'Called, interested'])->assertRedirect();
        $this->assertSame('contacted', $big->fresh()->status);

        $this->post('/admin/landlords', ['phone' => $big->phone, 'name' => 'Big', 'locale' => 'am', 'signup_id' => $big->id])->assertRedirect();
        $landlord = User::query()->where('phone', '+251922000001')->sole();
        $this->assertTrue($landlord->is_pilot);
        $this->assertSame(User::ROLE_LANDLORD, $landlord->role);
        $this->assertSame('pilot', $big->fresh()->status);
        $this->assertSame($landlord->id, $big->fresh()->landlord_id);
    }

    public function test_notebook_import_preview_then_confirm(): void
    {
        $landlord = User::factory()->create(['is_pilot' => true, 'locale' => 'am']);
        $this->actingAs($this->admin, 'web');

        $file = $this->csv([
            ['property' => 'Bole Compound', 'sub_city' => 'Bole', 'woreda' => '3', 'unit' => 'Room 1', 'tenant_name' => 'Almaz Tesfaye', 'tenant_phone' => '0911234567', 'monthly_rent_birr' => '12000', 'deposit_birr' => '24000', 'lease_start' => '2026-09-01', 'paid_through' => '2026-10'],
            ['property' => 'Bole Compound', 'unit' => 'Room 2', 'tenant_name' => 'Dawit Haile', 'tenant_phone' => '0922334455', 'monthly_rent_birr' => '15,000', 'lease_start' => '2019-01-01 EC'],
            ['property' => 'Bole Compound', 'unit' => 'Room 3'],
        ]);

        $this->post("/admin/landlords/{$landlord->id}/import", ['file' => $file])
            ->assertOk()
            ->assertSee('All 3 rows look good.')
            ->assertSee('2026-09-11'); // 1 Meskerem 2019 EC

        $this->assertSame(0, Unit::query()->withoutGlobalScopes()->count()); // nothing saved yet

        $this->post("/admin/landlords/{$landlord->id}/import/confirm")
            ->assertRedirect("/admin/landlords/{$landlord->id}")
            ->assertSessionHas('status', fn ($s) => str_contains($s, '1 properties, 3 units, 2 tenants, 2 leases, 1 opening payments'));

        $this->assertSame(3, Unit::query()->withoutGlobalScopes()->where('landlord_id', $landlord->id)->count());
        $this->assertSame(2, Unit::query()->withoutGlobalScopes()->where('status', 'occupied')->count());
        $this->assertSame(2, DB::table('consents')->where('landlord_id', $landlord->id)->count());

        $room1 = Lease::query()->withoutGlobalScopes()->whereHas('tenant', fn ($q) => $q->withoutGlobalScopes()->where('full_name', 'Almaz Tesfaye'))->sole();
        $this->assertSame('2028-08-31', $room1->end_date->toDateString());
        $statuses = Charge::query()->withoutGlobalScopes()->where('lease_id', $room1->id)->orderBy('period')->pluck('status', 'period')->all();
        $this->assertSame(['2026-09' => 'paid', '2026-10' => 'paid', '2026-11' => 'late'], $statuses);
        $this->assertSame(2_400_000, (int) Payment::query()->withoutGlobalScopes()->where('lease_id', $room1->id)->sum('amount'));

        // The landlord sees it all in the app.
        $this->actingAsLandlord($landlord);
        $this->getJson('/api/v1/units')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.label', 'Room 1')
            ->assertJsonPath('data.2.label', 'Room 3');
    }

    public function test_import_shows_errors_and_saves_nothing(): void
    {
        $landlord = User::factory()->create();
        $this->actingAs($this->admin, 'web');

        $this->post("/admin/landlords/{$landlord->id}/import", ['file' => $this->csv([
            ['property' => 'A', 'unit' => '1', 'tenant_name' => 'X', 'tenant_phone' => '123', 'monthly_rent_birr' => 'abc', 'lease_start' => '2026-02-30'],
            ['property' => 'A', 'unit' => '2', 'tenant_name' => 'Y', 'tenant_phone' => '0911000000', 'monthly_rent_birr' => '5000', 'lease_start' => '2026-01-01', 'lease_end' => '2026-12-31'],
            ['property' => 'A', 'unit' => '1'],
        ])])
            ->assertOk()
            ->assertSee('Fix these rows')
            ->assertSee('Tenant phone must be an Ethiopian mobile number.')
            ->assertSee('Monthly rent must be an amount in birr.')
            ->assertSee('Lease start must be a date')
            ->assertSee('Leases must run at least 2 years')
            ->assertSee('Same property and unit as line 2.')
            ->assertDontSee('Import 3 rows');

        $this->post("/admin/landlords/{$landlord->id}/import/confirm")->assertRedirect();
        $this->assertSame(0, Unit::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Tenant::query()->withoutGlobalScopes()->count());
    }

    public function test_template_downloads_and_parses_cleanly(): void
    {
        $this->actingAs($this->admin, 'web');
        $csv = $this->get('/admin/import-template.csv')->assertOk()->getContent();

        $result = app(OnboardingImport::class)->parse($csv, User::factory()->create());
        $this->assertSame([], $result['errors']);
        $this->assertCount(3, $result['rows']);
    }

    public function test_admin_can_change_the_rent_cap(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->put('/admin/settings', ['rent_cap_percent' => '12', 'rent_notice_days' => '60'])->assertRedirect();
        $this->assertSame('12', Setting::get(Setting::RENT_CAP_PERCENT));
        $this->assertSame('60', Setting::get(Setting::RENT_NOTICE_DAYS));

        $this->actingAsLandlord();
        $this->getJson('/api/v1/rules')->assertJsonPath('data.rent_cap_percent', 12);
    }

    public function test_admin_create_command(): void
    {
        $this->artisan('admin:create', ['phone' => '0933000000', '--name' => 'Abel'])->assertSuccessful();
        $this->assertTrue(User::query()->where('phone', '+251933000000')->sole()->isAdmin());

        $this->artisan('admin:create', ['phone' => '12'])->assertFailed();
    }
}
