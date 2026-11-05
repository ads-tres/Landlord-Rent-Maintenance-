<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payments\PaymentRecorder;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-11-15');
        $this->landlord = User::factory()->create(['locale' => 'en']);
        $property = Property::factory()->for($this->landlord, 'landlord')->create();
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'status' => 'occupied']);
        $tenant = Tenant::factory()->for($this->landlord, 'landlord')->create(['full_name' => 'Almaz Tesfaye', 'id_number' => 'AA-1']);
        $this->lease = Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
            'start_date' => '2026-10-01', 'end_date' => '2028-09-30',
        ]);
        app(RentSchedule::class)->sync($this->lease);
        app(PaymentRecorder::class)->record($this->lease, ['amount' => 1_000_000, 'paid_at' => '2026-10-02', 'method' => 'cash']);
        $this->post('/fix/'.$this->lease->repair_token, [
            'category' => 'water', 'description' => 'No water', 'photo' => UploadedFile::fake()->image('p.jpg'),
        ]);
    }

    public function test_landlord_can_export_a_tenants_data(): void
    {
        $this->actingAsLandlord($this->landlord);

        $this->get("/api/v1/tenants/{$this->lease->tenant_id}/export", ['Accept' => 'application/json'])
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="tenant-'.$this->lease->tenant_id.'-data.json"')
            ->assertJsonPath('tenant.full_name', 'Almaz Tesfaye')
            ->assertJsonPath('tenant.id_number', 'AA-1')
            ->assertJsonPath('payments.0.amount_santim', 1_000_000)
            ->assertJsonPath('payments.0.periods', ['2026-10'])
            ->assertJsonPath('repairs.0.description', 'No water')
            ->assertJsonCount(1, 'leases');
    }

    public function test_personal_data_can_be_removed_once_the_lease_has_ended(): void
    {
        $this->actingAsLandlord($this->landlord);
        $tenantId = $this->lease->tenant_id;

        $this->postJson("/api/v1/tenants/{$tenantId}/anonymize")->assertConflict();

        $this->postJson("/api/v1/leases/{$this->lease->id}/end", ['ended_on' => '2026-11-15'])->assertOk();
        $this->postJson("/api/v1/tenants/{$tenantId}/anonymize")
            ->assertOk()
            ->assertJsonPath('data.full_name', "Removed tenant #{$tenantId}")
            ->assertJsonPath('data.has_id_number', false);

        $tenant = Tenant::query()->find($tenantId);
        $this->assertNull($tenant->id_number);
        $this->assertNull($tenant->address);
        $this->assertSame('+251000000000', $tenant->phone);

        // Photos are gone; the payment amount stays for tax records.
        $this->assertSame(0, Attachment::query()->count());
        Storage::disk('local')->assertDirectoryEmpty('attachments/'.$this->landlord->id.'/2026/11');
        $this->assertSame(1_000_000, (int) Payment::query()->sum('amount'));
    }

    public function test_tenant_with_payments_cannot_be_hard_deleted(): void
    {
        $this->actingAsLandlord($this->landlord);
        $this->postJson("/api/v1/leases/{$this->lease->id}/end", ['ended_on' => '2026-11-15']);

        $this->deleteJson("/api/v1/tenants/{$this->lease->tenant_id}")
            ->assertConflict()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'personal details'));
    }

    public function test_another_landlord_cannot_export_or_anonymize(): void
    {
        $this->actingAsLandlord();

        $this->getJson("/api/v1/tenants/{$this->lease->tenant_id}/export")->assertNotFound();
        $this->postJson("/api/v1/tenants/{$this->lease->tenant_id}/anonymize")->assertNotFound();
    }

    public function test_privacy_page(): void
    {
        $this->get('/privacy?lang=am')->assertOk()->assertSee('የግላዊነት ማሳሰቢያ');
        $this->get('/privacy?lang=en')->assertOk()->assertSee('72 hours');
        $this->get('/?lang=en')->assertSee(route('privacy'));
    }
}
