<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            // Extra clauses the landlord adds to the printed agreement (house rules and the like).
            $table->text('extra_terms')->nullable()->after('condition_notes');
            // Where and under which number the signed agreement was registered.
            $table->string('registration_number', 60)->nullable()->after('registered_at');
            $table->string('registration_office', 120)->nullable()->after('registration_number');
        });
    }

    public function down(): void
    {
        Schema::table('leases', fn (Blueprint $t) => $t->dropColumn(['extra_terms', 'registration_number', 'registration_office']));
    }
};
