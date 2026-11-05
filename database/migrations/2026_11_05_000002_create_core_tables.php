<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Version 1 schema from the implementation guide.
 *
 * Every table except users carries landlord_id so one landlord can never
 * read another's data. Money is stored as integers in santim (1 birr = 100 santim).
 * Dates are stored in the Gregorian calendar; the Ethiopian calendar is display-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('sub_city')->nullable();
            $table->string('woreda')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->string('status', 20)->default('vacant'); // vacant | occupied
            $table->timestamps();

            $table->unique(['property_id', 'label']);
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->string('full_name');
            $table->string('phone', 20);
            $table->text('id_number')->nullable(); // encrypted cast
            $table->string('address')->nullable();
            $table->timestamps();

            $table->index(['landlord_id', 'phone']);
        });

        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedBigInteger('monthly_rent'); // santim
            $table->unsignedBigInteger('deposit')->default(0); // santim
            $table->unsignedTinyInteger('due_day')->default(1);
            $table->string('due_calendar', 10)->default('gregorian'); // gregorian | ethiopian
            $table->text('condition_notes')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->string('status', 20)->default('active'); // draft | active | ended
            $table->timestamps();
        });

        Schema::create('rent_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('old_rent');
            $table->unsignedBigInteger('new_rent');
            $table->date('effective_date');
            $table->decimal('cap_percent', 5, 2);
            $table->timestamps();
        });

        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7); // YYYY-MM
            $table->string('type', 20)->default('rent'); // rent | utility | other
            $table->unsignedBigInteger('amount_due');
            $table->date('due_date');
            $table->string('status', 20)->default('due'); // due | partial | paid | late
            $table->timestamps();

            $table->unique(['lease_id', 'period', 'type']);
            $table->index(['landlord_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('paid_at');
            $table->string('method', 20); // cash | telebirr | bank | chapa
            $table->string('reference')->nullable();
            $table->string('source', 20)->default('manual'); // manual | chapa
            $table->timestamps();
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamps();
        });

        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open'); // open | in_progress | done
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->morphs('attachable');
            $table->string('path');
            $table->string('mime', 100);
            $table->timestamps();
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30)->default('privacy_notice');
            $table->string('notice_version', 20);
            $table->string('locale', 2);
            $table->timestamp('given_at');
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'settings', 'consents', 'attachments', 'maintenance_requests',
            'payment_allocations', 'payments', 'charges', 'rent_changes',
            'leases', 'tenants', 'units', 'properties',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
