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

class AnnualReportTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2027-02-10 10:00');
        $this->landlord = User::factory()->create(['locale' => 'en', 'calendar_pref' => 'gregorian', 'name' => 'Abel']);
        $property = Property::factory()->for($this->landlord, 'landlord')->create(['name' => 'Bole Compound']);
        $let = Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 1', 'status' => 'occupied']);
        Unit::factory()->for($property)->create(['landlord_id' => $this->landlord->id, 'label' => 'Room 2']); // vacant
        $lease = Lease::factory()->create([
            'landlord_id' => $this->landlord->id, 'unit_id' => $let->id,
            'start_date' => '2026-07-01', 'end_date' => '2028-06-30', 'monthly_rent' => 1_000_000, 'due_day' => 1,
        ]);
        app(RentSchedule::class)->sync($lease);
        foreach (['2026-07-02', '2026-08-02', '2026-09-02'] as $date) {
            app(PaymentRecorder::class)->record($lease, ['amount' => 1_000_000, 'paid_at' => $date, 'method' => 'cash']);
        }
        $this->actingAsLandlord($this->landlord);
        $this->postJson('/api/v1/expenses', ['property_id' => $property->id, 'category' => 'repair', 'amount' => 200_000, 'spent_on' => '2026-08-15'])->assertCreated();
    }

    public function test_calendar_year_month_by_month(): void
    {
        $r = $this->getJson('/api/v1/reports/annual?year=2026&calendar=gregorian')->assertOk()->json('data');

        $this->assertSame(['2026-01-01', '2026-12-31'], [$r['from'], $r['to']]);
        $this->assertCount(12, $r['months']);
        $this->assertSame([3_000_000, 0, 200_000, 2_800_000], [$r['income'], $r['refunded'], $r['expenses'], $r['net']]);
        $august = collect($r['months'])->firstWhere('key', '2026-08');
        $this->assertSame([1_000_000, 200_000, 800_000, 1, 50], [$august['income'], $august['expenses'], $august['net'], $august['units_let'], $august['occupancy']]);
        $this->assertSame(0, collect($r['months'])->firstWhere('key', '2026-03')['occupancy']);
        $this->assertSame(25, $r['average_occupancy']); // 50% for six of twelve months
        $this->assertSame('Bole Compound', $r['by_property'][0]['property']);
    }

    public function test_ethiopian_fiscal_year_includes_pagume(): void
    {
        $r = $this->getJson('/api/v1/reports/annual?year=2019&calendar=ethiopian')->assertOk()->json('data');

        $this->assertSame('2026-07-08', $r['from']); // 1 Hamle 2018
        $this->assertCount(13, $r['months']);
        $this->assertSame(['2018-11', '2018-12', '2018-13', '2019-01'], array_slice(array_column($r['months'], 'key'), 0, 4));
        $this->assertStringStartsWith('Pagume', $r['months'][2]['label']);
        $this->assertSame('Fiscal year 2019', $r['label']);
    }

    public function test_pdf_excel_and_access(): void
    {
        $this->get('/api/v1/reports/annual?year=2026&calendar=gregorian&format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');

        $xlsx = $this->get('/api/v1/reports/annual?year=2026&calendar=gregorian&format=xlsx')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'yr').'.xlsx';
        file_put_contents($path, $xlsx->streamedContent());
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        $this->assertEquals(30000, collect($rows)->firstWhere(0, 'Total received')[1]);

        $this->getJson('/api/v1/reports/annual')->assertOk()->assertJsonPath('data.year', 2027);
        $this->withHeader('Accept-Language', 'am')->get('/api/v1/reports/annual?year=2019&calendar=ethiopian&format=pdf')->assertOk();

        $caretaker = User::factory()->create(['role' => User::ROLE_CARETAKER]);
        $caretaker->forceFill(['landlord_id' => $this->landlord->id])->save();
        $this->actingAsLandlord($caretaker);
        $this->getJson('/api/v1/reports/annual')->assertForbidden();
    }
}
