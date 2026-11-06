<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Leases\LeaseAgreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeaseAgreementTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Property $property;

    private Unit $unit;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-11-15 10:00');
        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian', 'name' => 'Abel Kebede', 'phone' => '+251911000001']);
        $this->property = Property::factory()->for($this->landlord, 'landlord')->create([
            'name' => 'Bole Compound', 'sub_city' => 'Bole', 'woreda' => '03', 'address' => '1234',
        ]);
        $this->unit = Unit::factory()->for($this->property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 1', 'bedrooms' => 2, 'status' => 'occupied']);
        $this->lease = Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $this->unit->id,
            'start_date' => '2026-11-01', 'end_date' => '2028-10-31', 'monthly_rent' => 1_000_000,
            'deposit' => 2_000_000, 'due_day' => 5, 'condition_notes' => 'Freshly painted, new kitchen tap',
        ]);
        $this->lease->tenant->update(['full_name' => 'Almaz Tesfaye', 'phone' => '+251922000002', 'address' => null, 'id_number' => null]);
        $this->actingAsLandlord($this->landlord);
    }

    private function clauses(string $locale = 'en'): array
    {
        return collect(LeaseAgreement::build($this->lease->refresh(), $locale)['clauses'])->pluck('text', 'title')->all();
    }

    public function test_agreement_is_built_from_the_lease_with_blanks_for_missing_details(): void
    {
        $c = $this->clauses();

        $this->assertStringContainsString('Landlord: Abel Kebede, phone +251911000001, ID no. '.LeaseAgreement::BLANK.', address '.LeaseAgreement::BLANK, $c['The parties']);
        $this->assertStringContainsString('Tenant: Almaz Tesfaye, phone +251922000002, ID no. '.LeaseAgreement::BLANK.', address '.LeaseAgreement::BLANK, $c['The parties']);
        $this->assertStringContainsString('the home Room 1 (2 bedroom(s)) at Bole Compound (Bole, Woreda 03, house no. 1234)', $c['The home']);
        $this->assertStringContainsString('from 1 November 2026 (', $c['Term']);
        $this->assertStringContainsString('(2 years)', $c['Term']);
        $this->assertStringContainsString('The monthly rent is Br 10,000. The tenant pays it on or before day 5 of each month.', $c['Rent and payment']);
        $this->assertStringNotContainsString('online', $c['Rent and payment']);
        $this->assertStringContainsString('deposit of Br 20,000', $c['Deposit']);
        $this->assertStringContainsString('pays for the electricity, water and other services used in the home directly', $c['Electricity and water']);
        $this->assertStringContainsString('(currently 11.5%)', $c['Changes to the rent']);
        $this->assertStringContainsString('Freshly painted, new kitchen tap', $c['Condition of the home at the start']);
        $this->assertArrayNotHasKey('Additional terms', $c);

        $this->getJson("/api/v1/leases/{$this->lease->id}")
            ->assertJsonPath('data.agreement_blanks', ['landlord_id_number', 'tenant_address', 'tenant_id_number']);
    }

    public function test_landlord_profile_details_print_on_the_agreement(): void
    {
        $this->patchJson('/api/v1/auth/me', ['id_number' => 'AA-998877', 'address' => 'Bole, Woreda 03, house 55'])
            ->assertOk()
            ->assertJsonPath('data.id_number', 'AA-998877')
            ->assertJsonPath('data.address', 'Bole, Woreda 03, house 55');
        $this->assertNotSame('AA-998877', \DB::table('users')->where('id', $this->landlord->id)->value('id_number')); // encrypted

        $this->assertStringContainsString(
            'Landlord: Abel Kebede, phone +251911000001, ID no. AA-998877, address Bole, Woreda 03, house 55.',
            $this->clauses()['The parties'],
        );
        $this->getJson("/api/v1/leases/{$this->lease->id}")
            ->assertJsonPath('data.agreement_blanks', ['tenant_address', 'tenant_id_number']);
    }

    public function test_shared_meters_extra_terms_and_amharic(): void
    {
        $other = Unit::factory()->for($this->property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 2']);
        $this->postJson("/api/v1/properties/{$this->property->id}/meters", [
            'name' => 'Main electricity', 'kind' => 'electricity', 'split_method' => 'percent',
            'units' => [['unit_id' => $this->unit->id, 'share_percent' => 60], ['unit_id' => $other->id, 'share_percent' => 40]],
        ])->assertCreated();

        $this->putJson("/api/v1/leases/{$this->lease->id}/extra-terms", ['extra_terms' => 'No pets. Quiet after 10pm.'])
            ->assertOk()->assertJsonPath('data.extra_terms', 'No pets. Quiet after 10pm.');

        $c = $this->clauses();
        $this->assertStringContainsString('Main electricity (electricity), split by the agreed shares (this home: 60%)', $c['Electricity and water']);
        $this->assertSame('No pets. Quiet after 10pm.', $c['Additional terms']);

        $am = $this->clauses('am');
        $this->assertStringContainsString('ወርሃዊ ኪራዩ', $am['ኪራይ እና አከፋፈል']);
        $this->assertStringContainsString('የዚህ ቤት ድርሻ 60%', $am['መብራት እና ውሃ']);
        $this->assertCount(count($c), $am); // clauses line up for the two-language version

        $this->putJson("/api/v1/leases/{$this->lease->id}/extra-terms", ['extra_terms' => null])->assertOk()->assertJsonPath('data.extra_terms', null);
    }

    public function test_agreement_downloads_as_pdf_in_each_language(): void
    {
        foreach (['en', 'am', 'both'] as $language) {
            $this->get("/api/v1/leases/{$this->lease->id}/agreement?language={$language}")
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf')
                ->assertHeader('content-disposition', 'attachment; filename=lease-agreement-'.$this->lease->id.'.pdf');
        }
        $this->get("/api/v1/leases/{$this->lease->id}/agreement")->assertOk(); // landlord's language
        $this->getJson("/api/v1/leases/{$this->lease->id}/agreement?language=fr")->assertUnprocessable();
    }

    public function test_registration_is_recorded_and_unregistered_leases_are_counted(): void
    {
        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.unregistered_leases', 1);
        $this->getJson('/api/v1/leases?unregistered=1')->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/leases/{$this->lease->id}/registration", ['registered_at' => '2026-12-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('registered_at'); // in the future

        $this->putJson("/api/v1/leases/{$this->lease->id}/registration", [
            'registered_at' => '2026-11-10', 'registration_number' => 'BL/03/1234', 'registration_office' => 'Bole Woreda 03',
        ])->assertOk()
            ->assertJsonPath('data.registered_at', '2026-11-10')
            ->assertJsonPath('data.registration_number', 'BL/03/1234')
            ->assertJsonPath('data.registration_office', 'Bole Woreda 03');

        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.unregistered_leases', 0);
        $this->getJson('/api/v1/leases?unregistered=1')->assertJsonCount(0, 'data');

        $this->deleteJson("/api/v1/leases/{$this->lease->id}/registration")->assertOk()->assertJsonPath('data.registered_at', null);
    }

    public function test_photos_of_the_signed_copy_are_kept(): void
    {
        $this->post("/api/v1/leases/{$this->lease->id}/documents", ['photo' => UploadedFile::fake()->image('page1.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(1, 'data.documents');
        $this->getJson("/api/v1/leases/{$this->lease->id}")->assertJsonCount(1, 'data.documents');
    }

    public function test_caretakers_and_other_landlords_cannot_use_it(): void
    {
        $caretaker = User::factory()->create(['role' => User::ROLE_CARETAKER]);
        $caretaker->forceFill(['landlord_id' => $this->landlord->id])->save();
        $this->actingAsLandlord($caretaker);
        $this->get("/api/v1/leases/{$this->lease->id}/agreement", ['Accept' => 'application/json'])->assertForbidden();
        $this->putJson("/api/v1/leases/{$this->lease->id}/registration", ['registered_at' => '2026-11-10'])->assertForbidden();

        $this->actingAsLandlord(User::factory()->create());
        $this->get("/api/v1/leases/{$this->lease->id}/agreement", ['Accept' => 'application/json'])->assertNotFound();
    }
}
