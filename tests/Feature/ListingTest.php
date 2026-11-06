<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\Listing;
use App\Models\Property;
use App\Models\TelegramLink;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ListingTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-11-15 10:00');
        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian', 'name' => 'Abel']);
        $property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound', 'sub_city' => 'Bole', 'woreda' => '3']);
        $this->unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 3', 'bedrooms' => 2, 'status' => 'vacant']);

        // The previous tenant paid Br 10,000.
        Lease::factory()->create(['landlord_id' => $this->landlord->id, 'unit_id' => $this->unit->id, 'monthly_rent' => 1_000_000, 'status' => Lease::STATUS_ENDED, 'start_date' => '2024-09-01', 'end_date' => '2026-08-31']);
        $this->actingAsLandlord($this->landlord);
    }

    private function createListing(int $rent = 1_100_000): int
    {
        return $this->postJson("/api/v1/units/{$this->unit->id}/listing", [
            'asking_rent' => $rent, 'available_from' => '2026-12-01', 'description' => 'Sunny, water tank, near the main road.',
        ])->assertCreated()->json('data.id');
    }

    private function publish(int $id): string
    {
        return $this->patchJson("/api/v1/listings/{$id}", ['status' => 'live'])->assertOk()->json('data.url');
    }

    /** Applies on the public page and confirms the phone; returns the application. */
    private function applyAs(string $url, string $phone, string $name, bool $verify = true): Application
    {
        $slug = basename($url);
        $this->post("/l/{$slug}/apply", ['full_name' => $name, 'phone' => $phone, 'household_size' => 3, 'move_in_date' => '2026-12-01', 'privacy' => '1'])
            ->assertRedirect("/l/{$slug}/verify");

        $normalized = PhoneNumber::normalize($phone);
        if ($verify) {
            $this->post("/l/{$slug}/verify", ['phone' => $normalized, 'code' => $this->sms->lastCodeFor($normalized)])->assertOk()->assertSee('Application sent');
        }

        return Application::query()->withoutGlobalScopes()->where('phone', $normalized)->sole();
    }

    public function test_listing_warns_when_asking_rent_is_above_the_cap(): void
    {
        $ok = $this->createListing(1_100_000);
        $this->getJson("/api/v1/listings/{$ok}")
            ->assertJsonPath('data.rent_check.last_rent', 1_000_000)
            ->assertJsonPath('data.rent_check.max_allowed', 1_115_000)
            ->assertJsonPath('data.rent_check.exceeds', false);

        $this->patchJson("/api/v1/listings/{$ok}", ['asking_rent' => 1_200_000])->assertJsonPath('data.rent_check.exceeds', true);
    }

    public function test_only_vacant_units_and_one_open_listing(): void
    {
        $this->createListing();
        $this->postJson("/api/v1/units/{$this->unit->id}/listing", ['asking_rent' => 1_000_000, 'available_from' => '2026-12-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('unit');

        $occupied = Unit::factory()->for($this->unit->property)->create(['landlord_id' => $this->landlord->id, 'status' => 'occupied']);
        $this->postJson("/api/v1/units/{$occupied->id}/listing", ['asking_rent' => 1_000_000, 'available_from' => '2026-12-01'])
            ->assertUnprocessable();
    }

    public function test_publishing_gives_a_public_page_and_share_text(): void
    {
        $id = $this->createListing();
        $slug = Listing::query()->find($id)->slug;
        $this->get("/l/{$slug}")->assertStatus(410); // draft is not public

        $this->postJson("/api/v1/listings/{$id}/photos", ['photo' => UploadedFile::fake()->image('room.jpg', 800, 600)])->assertOk();
        $url = $this->publish($id);

        $listing = $this->getJson("/api/v1/listings/{$id}")
            ->assertJsonPath('data.status', 'live')
            ->assertJsonPath('data.expires_at', fn ($v) => str_starts_with($v, '2026-12-15'))
            ->json('data');
        $this->assertStringContainsString("For rent: 2-bedroom home in Bole, Woreda 3\nBr 11,000 per month, available from 1 December 2026.\nApply here: {$url}", $listing['share_text']['en']);
        $this->assertStringContainsString('ባለ 2 መኝታ ቤት', $listing['share_text']['am']);

        $html = $this->get($url.'?lang=en')->assertOk()->assertSee('Br 11,000')->assertSee('Sunny, water tank')->assertSee('signature=', false)->getContent();
        $this->assertLessThan(100 * 1024, strlen($html));
    }

    public function test_applicants_confirm_their_phone_before_the_landlord_sees_them(): void
    {
        $url = $this->publish($this->createListing());

        $this->applyAs($url, '0922000001', 'Mulu', verify: false);
        $this->assertStringContainsString('code to confirm your rental application', $this->sms->sent[0]['message']);
        $this->getJson('/api/v1/listings/'.Listing::query()->value('id').'/applications')->assertJsonCount(0, 'data');

        $this->applyAs($url, '0922000002', 'Kebede');
        $this->getJson('/api/v1/listings/'.Listing::query()->value('id').'/applications')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Kebede')
            ->assertJsonPath('data.0.household_size', 3);
    }

    public function test_application_form_is_validated(): void
    {
        $slug = basename($this->publish($this->createListing()));

        $this->post("/l/{$slug}/apply", ['full_name' => '', 'phone' => '123', 'move_in_date' => '2026-01-01'])
            ->assertSessionHasErrors(['full_name', 'phone', 'move_in_date', 'privacy']);
        $this->post("/l/{$slug}/apply", ['full_name' => 'X', 'phone' => '0922000001', 'privacy' => '1', 'website' => 'spam'])
            ->assertSessionHasErrors('website');
        $this->post("/l/{$slug}/verify", ['phone' => '+251922000009', 'code' => '123456'])->assertSessionHasErrors('code');
    }

    public function test_approving_creates_the_tenant_and_a_draft_lease_and_tells_everyone(): void
    {
        $id = $this->createListing();
        $url = $this->publish($id);
        $chosen = $this->applyAs($url, '0922000001', 'Mulu Tadesse');
        $other = $this->applyAs($url, '0922000002', 'Kebede');
        $this->applyAs($url, '0922000003', 'Unverified', verify: false);
        $sentBefore = count($this->sms->sent);

        $this->patchJson("/api/v1/applications/{$other->id}", ['status' => 'shortlisted'])->assertJsonPath('data.status', 'shortlisted');
        $leaseId = $this->patchJson("/api/v1/applications/{$chosen->id}", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->json('data.lease_id');

        $lease = Lease::query()->find($leaseId);
        $this->assertSame(Lease::STATUS_DRAFT, $lease->status);
        $this->assertSame(1_100_000, $lease->monthly_rent);
        $this->assertSame('2026-12-01', $lease->start_date->toDateString());
        $this->assertSame('2028-11-30', $lease->end_date->toDateString());
        $this->assertSame('Mulu Tadesse', Tenant::query()->find($lease->tenant_id)->full_name);
        $this->assertDatabaseHas('consents', ['tenant_id' => $lease->tenant_id, 'kind' => 'privacy_notice']);
        $this->assertSame('closed', Listing::query()->find($id)->status);
        $this->assertSame('rejected', $other->fresh()->status);
        $this->assertNotNull($other->fresh()->delete_after);

        $newSms = array_slice($this->sms->sent, $sentBefore);
        $this->assertCount(2, $newSms); // chosen + the other verified applicant, not the unverified one
        $this->assertStringContainsString('was accepted', $newSms[0]['message']);
        $this->assertStringContainsString('Another applicant was chosen', $newSms[1]['message']);

        // The unit stays vacant until the draft lease is activated.
        $this->assertSame('vacant', $this->unit->fresh()->status);
    }

    public function test_activating_the_draft_lease_starts_rent(): void
    {
        $url = $this->publish($this->createListing());
        $app = $this->applyAs($url, '0922000001', 'Mulu');
        $leaseId = $this->patchJson("/api/v1/applications/{$app->id}", ['status' => 'approved'])->json('data.lease_id');

        $this->postJson("/api/v1/leases/{$leaseId}/activate", [])->assertUnprocessable()->assertJsonValidationErrors('condition_notes');

        $this->postJson("/api/v1/leases/{$leaseId}/activate", ['condition_notes' => 'Freshly painted', 'start_date' => '2026-11-15', 'end_date' => '2028-11-14', 'deposit' => 2_200_000])
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame('occupied', $this->unit->fresh()->status);
        $this->assertNull($this->unit->fresh()->vacant_since);
        $this->assertSame(1, Charge::query()->where('lease_id', $leaseId)->count());
        $this->postJson("/api/v1/leases/{$leaseId}/activate", ['condition_notes' => 'x'])->assertStatus(409);
    }

    public function test_reports_suspend_a_listing(): void
    {
        $url = $this->publish($this->createListing());
        $slug = basename($url);

        foreach (range(1, 3) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->post("/l/{$slug}/report")->assertRedirect();
        }

        $this->assertSame('suspended', Listing::query()->value('status'));
        $this->get($url)->assertStatus(410);
    }

    public function test_daily_job_expires_purges_and_reminds_about_long_vacancies(): void
    {
        config(['telegram.token' => 'TEST']);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        TelegramLink::query()->create(['landlord_id' => $this->landlord->id, 'linkable_type' => $this->landlord->getMorphClass(), 'linkable_id' => $this->landlord->id, 'chat_id' => 9, 'consented_at' => now()]);

        $url = $this->publish($this->createListing());
        $app = $this->applyAs($url, '0922000001', 'Mulu');
        $this->patchJson("/api/v1/applications/{$app->id}", ['status' => 'rejected']);
        $this->unit->forceFill(['vacant_since' => now()->subMonths(3)->subDay()])->saveQuietly();

        $this->artisan('listings:daily')->expectsOutput('Expired 0 listing(s), deleted 0 application(s), 1 vacancy reminder(s).');
        Http::assertSent(fn ($r) => str_contains((string) $r['text'], 'Room 3 at Bole Compound has been empty for 3 months'));
        $this->artisan('listings:daily')->expectsOutput('Expired 0 listing(s), deleted 0 application(s), 0 vacancy reminder(s).');

        $this->travel(31)->days();
        $this->artisan('listings:daily')->expectsOutput('Expired 1 listing(s), deleted 1 application(s), 0 vacancy reminder(s).');
        $this->travel(30)->days(); // now 5 months
        $this->artisan('listings:daily')->expectsOutput('Expired 0 listing(s), deleted 0 application(s), 1 vacancy reminder(s).');
    }

    public function test_ending_a_lease_starts_the_vacancy_clock(): void
    {
        $occupied = Unit::factory()->for($this->unit->property)->create(['landlord_id' => $this->landlord->id, 'status' => 'occupied']);
        $this->assertNull($occupied->vacant_since);
        $lease = Lease::factory()->create(['landlord_id' => $this->landlord->id, 'unit_id' => $occupied->id, 'start_date' => '2026-01-01', 'end_date' => '2027-12-31']);

        $this->postJson("/api/v1/leases/{$lease->id}/end", ['ended_on' => '2026-11-15'])->assertOk();

        $this->assertSame('2026-11-15', $occupied->fresh()->vacant_since->toDateString());
    }

    public function test_another_landlord_cannot_see_or_manage_listings(): void
    {
        $id = $this->createListing();
        $url = $this->publish($id);
        $app = $this->applyAs($url, '0922000001', 'Mulu');

        $this->actingAsLandlord();

        $this->getJson('/api/v1/listings')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/listings/{$id}")->assertNotFound();
        $this->getJson("/api/v1/listings/{$id}/applications")->assertNotFound();
        $this->patchJson("/api/v1/applications/{$app->id}", ['status' => 'approved'])->assertNotFound();
        $this->postJson("/api/v1/units/{$this->unit->id}/listing", ['asking_rent' => 100_000, 'available_from' => '2026-12-01'])->assertNotFound();
    }
}
