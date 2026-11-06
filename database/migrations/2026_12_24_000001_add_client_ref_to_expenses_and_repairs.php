<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set by the app when an expense or repair is logged offline; resending it never creates a duplicate.
        foreach (['expenses', 'maintenance_requests'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('client_ref', 64)->nullable();
                $table->unique(['landlord_id', 'client_ref']);
            });
        }
    }

    public function down(): void
    {
        foreach (['expenses', 'maintenance_requests'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['landlord_id', 'client_ref']);
                $table->dropColumn('client_ref');
            });
        }
    }
};
