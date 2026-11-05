<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Set by the app when a payment is logged offline; resending it never creates a duplicate.
            $table->string('client_ref', 64)->nullable()->after('source');
            $table->unique(['landlord_id', 'client_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['landlord_id', 'client_ref']);
            $table->dropColumn('client_ref');
        });
    }
};
