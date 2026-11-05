<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_tenant_records_privacy_consent(): void
    {
        $landlord = $this->actingAsLandlord();

        $response = $this->postJson('/api/v1/tenants', [
            'full_name' => 'Abebe Kebede',
            'phone' => '0922 111 222',
            'id_number' => 'AA-123456',
            'privacy_notice_shown' => true,
        ])->assertCreated()
            ->assertJsonPath('data.phone', '+251922111222')
            ->assertJsonPath('data.has_id_number', true)
            ->assertJsonMissingPath('data.id_number');

        $tenantId = $response->json('data.id');
        $this->assertDatabaseHas('consents', [
            'tenant_id' => $tenantId,
            'landlord_id' => $landlord->id,
            'kind' => 'privacy_notice',
        ]);

        $this->getJson("/api/v1/tenants/{$tenantId}")->assertOk()->assertJsonPath('data.id_number', 'AA-123456');
    }

    public function test_id_number_is_encrypted_at_rest(): void
    {
        $this->actingAsLandlord();

        $id = $this->postJson('/api/v1/tenants', [
            'full_name' => 'Abebe Kebede',
            'phone' => '0922111222',
            'id_number' => 'AA-123456',
            'privacy_notice_shown' => true,
        ])->json('data.id');

        $raw = DB::table('tenants')->where('id', $id)->value('id_number');
        $this->assertNotSame('AA-123456', $raw);
        $this->assertSame('AA-123456', Tenant::query()->find($id)->id_number);
    }

    public function test_privacy_notice_confirmation_is_required(): void
    {
        $this->actingAsLandlord();

        $this->postJson('/api/v1/tenants', ['full_name' => 'Abebe', 'phone' => '0922111222'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('privacy_notice_shown');
    }

    public function test_tenants_can_be_searched_updated_and_deleted(): void
    {
        $landlord = $this->actingAsLandlord();
        $tenant = Tenant::factory()->for($landlord, 'landlord')->create(['full_name' => 'Almaz Tesfaye']);
        Tenant::factory()->for($landlord, 'landlord')->create(['full_name' => 'Dawit Haile']);

        $this->getJson('/api/v1/tenants?q=Almaz')->assertOk()->assertJsonCount(1, 'data');

        $this->patchJson("/api/v1/tenants/{$tenant->id}", ['address' => 'Bole, Woreda 3'])
            ->assertOk()
            ->assertJsonPath('data.address', 'Bole, Woreda 3');

        $this->deleteJson("/api/v1/tenants/{$tenant->id}")->assertNoContent();
    }
}
