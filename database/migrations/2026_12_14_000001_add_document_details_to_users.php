<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Printed on lease agreements and letters; the ID number is encrypted at rest.
            $table->text('id_number')->nullable()->after('name');
            $table->string('address')->nullable()->after('id_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['id_number', 'address']));
    }
};
