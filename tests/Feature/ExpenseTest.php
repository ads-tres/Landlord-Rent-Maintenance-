<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payments\PaymentRecorder;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseTest extends TestCase
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
        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian']);
        $this->property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);
        $this->unit = Unit::factory()->for($this->property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 1', 'status' => 'occupied']);
        $this->lease = Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $this->unit->id,
            'start_date' => '2026-10-01', 'end_date' => '2028-09-30', 'monthly_rent' => 1_000_000,
        ]);
        app(RentSchedule::class)->sync($this->lease);
        $this->actingAsLandlord($this->landlord);
    }

    public function test_landlord_records_an_expense_with_a_receipt_photo(): void
    {
        $id = $this->post('/api/v1/expenses', [
            'property_id' => $this->property->id, 'unit_id' => $this->unit->id, 'category' => 'land_lease',
            'amount' => 1_200_000, 'spent_on' => '2026-11-10', 'description' => 'Yearly land lease', 'vendor' => 'Bole sub-city',
            'photo' => UploadedFile::fake()->image('receipt.jpg', 2000, 1500),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.property_name', 'Bole Compound')
            ->assertJsonPath('data.unit_label', 'Room 1')
            ->assertJsonPath('data.source', null)
            ->assertJsonCount(1, 'data.photos')
            ->json('data.id');

        $this->post("/api/v1/expenses/{$id}/photos", ['photo' => UploadedFile::fake()->image('b.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(2, 'data.photos');

        $this->patchJson("/api/v1/expenses/{$id}", ['amount' => 1_300_000])->assertOk()->assertJsonPath('data.amount', 1_300_000);
        $this->getJson('/api/v1/expenses?category=land_lease')->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1_300_000);
        $this->getJson('/api/v1/expenses?category=tax')->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.expenses_this_month', 1_300_000);

        $this->deleteJson("/api/v1/expenses/{$id}")->assertNoContent();
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_expense_is_validated(): void
    {
        $other = Property::factory()->for($this->landlord, 'landlord')->create();
        $this->postJson('/api/v1/expenses', [
            'property_id' => $other->id, 'unit_id' => $this->unit->id, 'category' => 'repair', 'amount' => 100, 'spent_on' => '2026-11-10',
        ])->assertUnprocessable()->assertJsonValidationErrors('unit_id');

        $this->postJson('/api/v1/expenses', [
            'property_id' => $this->property->id, 'category' => 'bribe', 'amount' => 0, 'spent_on' => '2026-12-01',
        ])->assertUnprocessable()->assertJsonValidationErrors(['category', 'amount', 'spent_on']);
    }

    public function test_repair_cost_becomes_an_expense_and_follows_changes(): void
    {
        $this->post('/fix/'.$this->lease->repair_token, ['category' => 'water', 'description' => 'Leaking pipe']);
        $id = $this->getJson('/api/v1/maintenance')->json('data.0.id');

        $this->patchJson("/api/v1/maintenance/{$id}", ['status' => 'done', 'cost' => 85_000, 'notify_tenant' => false])
            ->assertOk()->assertJsonPath('data.cost', 85_000);

        $expense = $this->getJson('/api/v1/expenses')->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(['repair', 85_000, '2026-11-15', 'repair', $this->unit->id], [
            $expense['category'], $expense['amount'], $expense['spent_on'], $expense['source']['type'], $expense['unit_id'],
        ]);

        // Automatic expenses are changed on the repair, not directly.
        $this->patchJson("/api/v1/expenses/{$expense['id']}", ['amount' => 1])->assertUnprocessable();
        $this->deleteJson("/api/v1/expenses/{$expense['id']}")->assertUnprocessable();

        $this->patchJson("/api/v1/maintenance/{$id}", ['cost' => 90_000])->assertOk();
        $this->assertSame(1, Expense::query()->count());
        $this->assertSame(90_000, Expense::query()->value('amount'));

        $this->patchJson("/api/v1/maintenance/{$id}", ['cost' => null])->assertOk();
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_service_cost_and_vacant_units_utility_share_become_expenses(): void
    {
        $asset = $this->postJson("/api/v1/properties/{$this->property->id}/assets", [
            'kind' => 'generator', 'name' => 'Generator', 'service_interval_days' => 90,
        ])->json('data.id');
        $this->postJson("/api/v1/assets/{$asset}/services", ['serviced_on' => '2026-11-14', 'cost' => 350_000, 'notes' => 'Oil change'])->assertOk();
        $this->postJson("/api/v1/assets/{$asset}/services", ['serviced_on' => '2026-11-15'])->assertOk(); // no cost, no expense

        $vacant = Unit::factory()->for($this->property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 2']);
        $meter = $this->postJson("/api/v1/properties/{$this->property->id}/meters", [
            'name' => 'Main electricity', 'kind' => 'electricity', 'split_method' => 'equal',
            'units' => [['unit_id' => $this->unit->id], ['unit_id' => $vacant->id]],
        ])->json('data.id');
        $bill = $this->postJson("/api/v1/meters/{$meter}/bills", ['period' => '2026-11', 'amount' => 100_000, 'due_date' => '2026-11-25'])
            ->assertCreated()->json('data.id');

        $rows = collect($this->getJson('/api/v1/expenses')->json('data'))->keyBy('category');
        $this->assertSame(350_000, $rows['maintenance']['amount']);
        $this->assertSame('Generator: Oil change', $rows['maintenance']['description']);
        $this->assertSame(50_000, $rows['utility']['amount']);
        $this->assertSame('utility_bill', $rows['utility']['source']['type']);
        $this->assertCount(2, $rows);

        $this->deleteJson("/api/v1/bills/{$bill}")->assertNoContent();
        $this->assertFalse(Expense::query()->where('category', 'utility')->exists());
    }

    public function test_quarterly_report_shows_income_expenses_and_net(): void
    {
        app(PaymentRecorder::class)->record($this->lease, ['amount' => 1_000_000, 'paid_at' => '2026-10-05', 'method' => 'cash']);
        app(PaymentRecorder::class)->record($this->lease, ['amount' => 1_000_000, 'paid_at' => '2026-11-05', 'method' => 'cash']);
        foreach ([['repair', 150_000, '2026-10-20'], ['land_lease', 400_000, '2026-11-10'], ['repair', 50_000, '2026-11-12'], ['tax', 99_000, '2026-09-30']] as [$cat, $amount, $date]) {
            $this->postJson('/api/v1/expenses', ['property_id' => $this->property->id, 'category' => $cat, 'amount' => $amount, 'spent_on' => $date])->assertCreated();
        }

        $this->getJson('/api/v1/reports/quarterly?year=2026&quarter=4&calendar=gregorian')
            ->assertOk()
            ->assertJsonPath('data.total', 2_000_000)
            ->assertJsonPath('data.expenses_total', 600_000) // September's tax is in Q3
            ->assertJsonPath('data.expenses_count', 3)
            ->assertJsonPath('data.net', 1_400_000)
            ->assertJsonPath('data.expenses_by_category.0', ['category' => 'land_lease', 'label' => 'Land lease', 'total' => 400_000])
            ->assertJsonPath('data.expenses_by_category.1.total', 200_000)
            ->assertJsonPath('data.by_property.0.expenses', 600_000);

        $pdf = $this->get('/api/v1/reports/quarterly?year=2026&quarter=4&calendar=gregorian&format=pdf');
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_another_landlord_cannot_see_or_change_expenses(): void
    {
        $id = $this->postJson('/api/v1/expenses', ['property_id' => $this->property->id, 'category' => 'other', 'amount' => 100, 'spent_on' => '2026-11-10'])->json('data.id');

        $other = User::factory()->create();
        $this->actingAsLandlord($other);
        $this->getJson('/api/v1/expenses')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/expenses/{$id}")->assertNotFound();
        $this->patchJson("/api/v1/expenses/{$id}", ['amount' => 5])->assertNotFound();
        $this->postJson('/api/v1/expenses', ['property_id' => $this->property->id, 'category' => 'other', 'amount' => 100, 'spent_on' => '2026-11-10'])->assertNotFound();
    }
}
