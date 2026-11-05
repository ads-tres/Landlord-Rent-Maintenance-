<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Rent\RentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupAndOfflineTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_writes_a_gzip_file_and_prunes_old_ones(): void
    {
        Storage::fake('local');
        $db = tempnam(sys_get_temp_dir(), 'db');
        file_put_contents($db, 'SQLite format 3'.str_repeat("\0", 1000));
        config(['database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $db], 'database.default' => 'backup_test']);

        Storage::disk('local')->put('backups/old.sql.gz', 'x');
        touch(Storage::disk('local')->path('backups/old.sql.gz'), now()->subDays(20)->getTimestamp());

        $this->artisan('backup:database')->assertSuccessful();

        $files = Storage::disk('local')->files('backups');
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('backups/backup_test-', $files[0]);
        $this->assertStringStartsWith('SQLite format 3', gzdecode(Storage::disk('local')->get($files[0])));

        config(['database.default' => 'sqlite']);
        @unlink($db);
    }

    public function test_resending_an_offline_payment_does_not_duplicate_it(): void
    {
        $this->travelTo('2026-11-15');
        $landlord = $this->actingAsLandlord();
        $property = Property::factory()->for($landlord, 'landlord')->create();
        $unit = Unit::factory()->for($property)->create(['landlord_id' => $landlord->id]);
        $lease = Lease::factory()->create(['landlord_id' => $landlord->id, 'unit_id' => $unit->id, 'start_date' => '2026-11-01', 'end_date' => '2028-10-31']);
        app(RentSchedule::class)->sync($lease);

        $payload = [
            'lease_id' => $lease->id, 'amount' => 1_000_000, 'paid_at' => '2026-11-14',
            'method' => 'telebirr', 'reference' => 'TB777', 'client_ref' => 'a1b2c3d4-offline',
        ];

        $first = $this->postJson('/api/v1/payments', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/payments', $payload)->assertOk()->assertJsonPath('data.id', $first);

        $this->assertSame(1, Payment::query()->count());
        $this->assertCount(1, $this->sms->sent);
    }
}
