<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compound features: shared meters and utility bills, announcements, and
 * generator / water tank / pump service reminders. Amounts in santim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 20); // electricity | water
            $table->string('split_method', 20); // equal | percent | submeter
            $table->timestamps();
        });

        Schema::create('meter_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('meter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->decimal('share_percent', 5, 2)->nullable(); // percent method only
            $table->timestamps();
            $table->unique(['meter_id', 'unit_id']);
        });

        Schema::create('utility_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('meter_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7); // YYYY-MM the bill is for
            $table->unsignedBigInteger('amount');
            $table->date('due_date');
            $table->unsignedBigInteger('landlord_share')->default(0); // vacant units' part
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('utility_bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->decimal('previous', 12, 2);
            $table->decimal('current', 12, 2);
            $table->timestamps();
        });

        // A charge now comes from the rent schedule ('rent') or a utility bill ('bill:{id}'),
        // so a lease can have rent plus several utility charges in the same month.
        Schema::table('charges', function (Blueprint $table) {
            $table->string('source_key', 30)->default('rent')->after('type');
            $table->foreignId('utility_bill_id')->nullable()->after('source_key')->constrained()->cascadeOnDelete();
        });
        DB::table('charges')->update(['source_key' => 'rent']);
        Schema::table('charges', function (Blueprint $table) {
            $table->dropUnique(['lease_id', 'period', 'type']);
            $table->unique(['lease_id', 'period', 'source_key']);
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->unsignedSmallInteger('telegram_count')->default(0);
            $table->unsignedSmallInteger('sms_count')->default(0);
            $table->unsignedSmallInteger('sms_segments')->default(0);
            $table->timestamps();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // generator | water_tank | pump | other
            $table->string('name');
            $table->unsignedSmallInteger('service_interval_days');
            $table->date('last_serviced_on')->nullable();
            $table->date('last_reminded_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->date('serviced_on');
            $table->unsignedBigInteger('cost')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_services');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('announcements');
        Schema::table('charges', function (Blueprint $table) {
            $table->dropUnique(['lease_id', 'period', 'source_key']);
            $table->dropConstrainedForeignId('utility_bill_id');
            $table->dropColumn('source_key');
            $table->unique(['lease_id', 'period', 'type']);
        });
        Schema::dropIfExists('meter_readings');
        Schema::dropIfExists('utility_bills');
        Schema::dropIfExists('meter_units');
        Schema::dropIfExists('meters');
    }
};
