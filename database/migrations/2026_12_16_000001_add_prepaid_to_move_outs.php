<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('move_outs', function (Blueprint $table) {
            // Rent paid for months after the move-out, in santim: first covers unpaid charges, the rest is refunded.
            $table->unsignedBigInteger('prepaid')->default(0)->after('deposit_kept');
            $table->unsignedBigInteger('prepaid_to_unpaid')->default(0)->after('prepaid');
        });
    }

    public function down(): void
    {
        Schema::table('move_outs', fn (Blueprint $t) => $t->dropColumn(['prepaid', 'prepaid_to_unpaid']));
    }
};
