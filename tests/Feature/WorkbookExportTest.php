<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payments\PaymentRecorder;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class WorkbookExportTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2027-01-20 10:00');
        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian', 'name' => 'Abel']);
        $property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 1', 'status' => 'occupied']);
        $lease = Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $unit->id,
            'start_date' => '2026-10-01', 'end_date' => '2028-09-30', 'monthly_rent' => 1_000_000, 'due_day' => 1,
        ]);
        $lease->tenant->update(['full_name' => 'Almaz Tesfaye']);
        app(RentSchedule::class)->sync($lease);
        app(PaymentRecorder::class)->record($lease, ['amount' => 1_000_000, 'paid_at' => '2026-10-03', 'method' => 'telebirr', 'reference' => 'TB1']);
        app(PaymentRecorder::class)->record($lease, ['amount' => 1_000_000, 'paid_at' => '2026-11-02', 'method' => 'cash']);
        // December unpaid.
        $this->actingAsLandlord($this->landlord);
        $this->postJson('/api/v1/expenses', ['property_id' => $property->id, 'category' => 'repair', 'amount' => 250_050, 'spent_on' => '2026-11-10', 'description' => 'New tap'])->assertCreated();
    }

    /** @return array<string, list<list<mixed>>> rows by sheet name */
    private function read(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'wbt').'.xlsx';
        file_put_contents($path, $content);
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($path);

        return $sheets;
    }

    public function test_quarter_downloads_as_a_workbook_with_four_sheets(): void
    {
        $response = $this->get('/api/v1/reports/quarterly?year=2026&quarter=4&calendar=gregorian&format=xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
        $this->assertStringContainsString('rent-and-expenses-2026-Q4.xlsx', $response->headers->get('content-disposition'));

        $sheets = $this->read($response->streamedContent());
        $this->assertSame(['Summary', 'Payments', 'Expenses', 'Rent roll'], array_keys($sheets));

        $summary = collect($sheets['Summary'])->keyBy(0);
        $this->assertEquals(20000, $summary['Rent received (Br)'][1]);
        $this->assertEquals(2500.5, $summary['Expenses (Br)'][1]);
        $this->assertEquals(17499.5, $summary['Net (Br)'][1]);

        $payments = $sheets['Payments'];
        $this->assertSame('Date (Ethiopian)', $payments[0][1]);
        $this->assertCount(4, $payments); // header, 2 payments, total
        $this->assertSame(['Almaz Tesfaye', 'Bole Compound', 'Room 1', 'Telebirr', 'TB1'], [$payments[1][3], $payments[1][4], $payments[1][5], $payments[1][6], $payments[1][7]]);
        $this->assertEquals(10000, $payments[1][8]);
        $this->assertEquals(20000, end($payments)[8]);

        $expenses = $sheets['Expenses'];
        $this->assertSame(['Repairs', 'New tap', 'Manual'], [$expenses[1][4], $expenses[1][5], $expenses[1][8]]);

        $roll = $sheets['Rent roll'];
        $this->assertSame('Almaz Tesfaye', $roll[1][0]);
        $this->assertEquals([30000, 20000, 10000], [$roll[1][6], $roll[1][7], $roll[1][8]]); // charged, received, owed (December)
    }

    public function test_workbook_in_amharic_and_ethiopian_quarters(): void
    {
        $response = $this->withHeader('Accept-Language', 'am')
            ->get('/api/v1/reports/quarterly?year=2019&quarter=2&calendar=ethiopian&format=xlsx')
            ->assertOk();
        $sheets = $this->read($response->streamedContent());
        $this->assertSame(['ማጠቃለያ', 'ክፍያዎች', 'ወጪዎች', 'የኪራይ ዝርዝር'], array_keys($sheets));
    }

    public function test_caretakers_cannot_export(): void
    {
        $caretaker = User::factory()->create(['role' => User::ROLE_CARETAKER]);
        $caretaker->forceFill(['landlord_id' => $this->landlord->id])->save();
        $this->actingAsLandlord($caretaker);
        $this->get('/api/v1/reports/quarterly?format=xlsx', ['Accept' => 'application/json'])->assertForbidden();
    }
}
