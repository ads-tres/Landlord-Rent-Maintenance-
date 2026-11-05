<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            // Short random token for the tenant's repair link (/fix/{token}); works while the lease is active.
            $table->string('repair_token', 16)->nullable()->unique()->after('status');
        });

        DB::table('leases')->whereNull('repair_token')->orderBy('id')->each(function ($lease) {
            DB::table('leases')->where('id', $lease->id)->update(['repair_token' => Str::lower(Str::random(10))]);
        });

        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->foreignId('lease_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->string('category', 20)->default('other')->after('title');
            $table->string('source', 10)->default('landlord')->after('status'); // tenant | landlord
            $table->text('resolution_note')->nullable()->after('description');
            $table->index(['landlord_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropIndex(['landlord_id', 'status']);
            $table->dropConstrainedForeignId('lease_id');
            $table->dropColumn(['category', 'source', 'resolution_note']);
        });

        Schema::table('leases', function (Blueprint $table) {
            $table->dropUnique(['repair_token']);
            $table->dropColumn('repair_token');
        });
    }
};
