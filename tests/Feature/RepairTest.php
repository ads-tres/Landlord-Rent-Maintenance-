<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RepairTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Unit $unit;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-11-15 10:00');
        $this->landlord = User::factory()->create(['locale' => 'en', 'name' => 'Abel']);
        $property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);
        $this->unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 3', 'status' => 'occupied']);
        $this->lease = Lease::factory()->create(['landlord_id' => $this->landlord->id, 'unit_id' => $this->unit->id]);
    }

    private function repairUrl(): string
    {
        return '/fix/'.$this->lease->repair_token;
    }

    public function test_every_lease_gets_a_repair_link(): void
    {
        $this->assertMatchesRegularExpression('/^[a-z0-9]{10}$/', $this->lease->repair_token);

        $this->actingAsLandlord($this->landlord);
        $this->getJson("/api/v1/leases/{$this->lease->id}")
            ->assertJsonPath('data.repair_url', url($this->repairUrl()));
    }

    public function test_tenant_page_renders_small_and_in_both_languages(): void
    {
        $html = $this->get($this->repairUrl().'?lang=am')->assertOk()->assertSee('ጥገና ያሳውቁ')->assertSee('Room 3')->getContent();
        $this->assertLessThan(100 * 1024, strlen($html));

        $this->get($this->repairUrl().'?lang=en')->assertOk()->assertSee('Report a repair');
    }

    public function test_tenant_can_report_a_repair_with_a_photo(): void
    {
        $this->post($this->repairUrl(), [
            'category' => 'plumbing',
            'description' => 'Kitchen sink is leaking',
            'photo' => UploadedFile::fake()->image('leak.jpg', 3000, 2000),
        ])->assertRedirect($this->repairUrl())->assertSessionHas('sent');

        $request = MaintenanceRequest::query()->withoutGlobalScopes()->sole();
        $this->assertSame('tenant', $request->source);
        $this->assertSame('Plumbing / leak', $request->title);
        $this->assertSame($this->lease->tenant_id, $request->tenant_id);
        $this->assertSame($this->landlord->id, $request->landlord_id);

        $photo = Attachment::query()->withoutGlobalScopes()->sole();
        Storage::disk('local')->assertExists($photo->path);
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($photo->path));
        $this->assertSame([1280, 853], [$w, $h]);

        // The tenant sees it in their recent requests.
        $this->get($this->repairUrl().'?lang=en')->assertSee('Plumbing / leak')->assertSee('Received');
    }

    public function test_tenant_form_is_validated(): void
    {
        $this->post($this->repairUrl(), ['category' => 'spaceship', 'description' => ''])
            ->assertSessionHasErrors(['category', 'description']);
        $this->post($this->repairUrl(), ['category' => 'water', 'description' => 'No water', 'website' => 'x'])
            ->assertSessionHasErrors('website');

        $this->assertSame(0, MaintenanceRequest::query()->withoutGlobalScopes()->count());
    }

    public function test_link_stops_working_when_the_lease_ends_or_is_reset(): void
    {
        $old = $this->repairUrl();
        $this->actingAsLandlord($this->landlord);

        $this->postJson("/api/v1/leases/{$this->lease->id}/repair-link/reset")->assertOk();
        $this->get($old)->assertNotFound();

        $this->lease->refresh()->update(['status' => Lease::STATUS_ENDED]);
        $this->get($this->repairUrl())->assertNotFound();
        $this->get('/fix/zzzzzzzzzz')->assertNotFound();
    }

    public function test_landlord_can_text_the_link_to_the_tenant(): void
    {
        $this->actingAsLandlord($this->landlord);

        $this->postJson("/api/v1/leases/{$this->lease->id}/repair-link/send")->assertOk()->assertJsonPath('data.status', 'sent');

        $this->assertSame($this->lease->tenant->phone, $this->sms->sent[0]['phone']);
        $this->assertStringContainsString('To report a repair for Room 3, open '.url($this->repairUrl()), $this->sms->sent[0]['message']);
    }

    public function test_landlord_lists_and_updates_repairs_and_the_tenant_is_texted(): void
    {
        $this->post($this->repairUrl(), ['category' => 'water', 'description' => 'No water since morning']);
        $this->actingAsLandlord($this->landlord);

        $id = $this->getJson('/api/v1/maintenance?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unit.property_name', 'Bole Compound')
            ->assertJsonPath('data.0.tenant.full_name', $this->lease->tenant->full_name)
            ->json('data.0.id');

        $this->patchJson("/api/v1/maintenance/{$id}", ['status' => 'in_progress', 'note' => 'Plumber coming at 3pm'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
        $this->assertStringContainsString('is being worked on. Plumber coming at 3pm', $this->sms->sent[0]['message']);

        $this->patchJson("/api/v1/maintenance/{$id}", ['status' => 'done', 'notify_tenant' => false])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.closed_at', fn ($v) => $v !== null);
        $this->assertCount(1, $this->sms->sent);

        $this->getJson('/api/v1/maintenance?status=active')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.open_repairs', 0);
    }

    public function test_landlord_can_record_a_repair_with_photos(): void
    {
        $this->actingAsLandlord($this->landlord);

        $id = $this->post('/api/v1/maintenance', [
            'unit_id' => $this->unit->id,
            'title' => 'Replace broken window',
            'category' => 'other',
            'photos' => [UploadedFile::fake()->image('a.jpg', 800, 600)],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.source', 'landlord')
            ->assertJsonPath('data.lease_id', $this->lease->id)
            ->assertJsonCount(1, 'data.photos')
            ->json('data.id');

        $this->post("/api/v1/maintenance/{$id}/photos", ['photo' => UploadedFile::fake()->image('b.png', 400, 400)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(2, 'data.photos');

        $photoUrl = $this->getJson("/api/v1/maintenance/{$id}")->json('data.photos.0.url');
        $this->get($photoUrl)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_another_landlord_cannot_see_repairs_or_photos(): void
    {
        $this->post($this->repairUrl(), [
            'category' => 'water', 'description' => 'No water', 'photo' => UploadedFile::fake()->image('x.jpg'),
        ]);
        $request = MaintenanceRequest::query()->withoutGlobalScopes()->sole();
        $photo = Attachment::query()->withoutGlobalScopes()->sole();

        $this->actingAsLandlord();

        $this->getJson('/api/v1/maintenance')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/maintenance/{$request->id}")->assertNotFound();
        $this->patchJson("/api/v1/maintenance/{$request->id}", ['status' => 'done'])->assertNotFound();
        $this->get("/api/v1/attachments/{$photo->id}")->assertNotFound();
        $this->postJson("/api/v1/leases/{$this->lease->id}/repair-link/send")->assertNotFound();
        $this->post('/api/v1/maintenance', ['unit_id' => $this->unit->id, 'title' => 'x'], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    public function test_units_list_for_pickers(): void
    {
        // More than one unit, sorted naturally (Room 3 before Room 10).
        Unit::factory()->for($this->unit->property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 10']);
        $this->actingAsLandlord($this->landlord);

        $this->getJson('/api/v1/units')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Room 3')
            ->assertJsonPath('data.0.property_name', 'Bole Compound')
            ->assertJsonPath('data.0.tenant_name', $this->lease->tenant->full_name)
            ->assertJsonPath('data.1.label', 'Room 10');
    }
}
