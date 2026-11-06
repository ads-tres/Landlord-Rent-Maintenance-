<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->date('previous_end_date');
            $table->date('new_end_date');
            $table->unsignedBigInteger('old_rent'); // santim
            $table->unsignedBigInteger('new_rent');
            $table->date('rent_from'); // the day after the previous end
            $table->decimal('cap_percent', 5, 2);
            $table->timestamps();
        });

        Schema::table('leases', function (Blueprint $table) {
            // Last renewal reminder sent to the landlord (60 or 30 days before the end).
            $table->unsignedSmallInteger('renewal_reminder_days')->nullable()->after('portal_token');
        });
    }

    public function down(): void
    {
        Schema::table('leases', fn (Blueprint $t) => $t->dropColumn('renewal_reminder_days'));
        Schema::dropIfExists('lease_renewals');
    }
};
