<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per landlord per day they used the app: weekly active landlords for the pilot.
        Schema::create('landlord_activity_days', function (Blueprint $table) {
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->date('day');
            $table->primary(['landlord_id', 'day']);
            $table->index('day');
        });

        Schema::table('waitlist_signups', function (Blueprint $table) {
            $table->string('status', 20)->default('new')->after('source'); // new | contacted | pilot | declined
            $table->text('admin_notes')->nullable()->after('status');
            $table->foreignId('landlord_id')->nullable()->after('admin_notes')->constrained('users')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_pilot')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_pilot'));
        Schema::table('waitlist_signups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('landlord_id');
            $table->dropColumn(['status', 'admin_notes']);
        });
        Schema::dropIfExists('landlord_activity_days');
    }
};
