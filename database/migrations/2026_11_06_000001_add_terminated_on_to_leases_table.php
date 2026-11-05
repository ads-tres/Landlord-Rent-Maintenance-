<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            // Set when a lease ends before its end_date; end_date keeps the agreed term.
            $table->date('terminated_on')->nullable()->after('end_date');
            $table->index(['landlord_id', 'status', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropIndex(['landlord_id', 'status', 'end_date']);
            $table->dropColumn('terminated_on');
        });
    }
};
