<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Caretakers work on their employer's data: landlord_id is the landlord they work for.
            $table->foreignId('landlord_id')->nullable()->after('role')->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable()->after('landlord_id');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('recorded_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
        });
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->foreignId('handled_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', fn (Blueprint $t) => $t->dropConstrainedForeignId('handled_by'));
        Schema::table('payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('recorded_by'));
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('landlord_id');
            $t->dropColumn('removed_at');
        });
    }
};
