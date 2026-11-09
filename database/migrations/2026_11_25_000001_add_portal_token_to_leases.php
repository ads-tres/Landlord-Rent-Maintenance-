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
            $table->string('portal_token', 10)->nullable()->unique()->after('pay_token');
        });

        DB::table('leases')->whereNull('portal_token')->orderBy('id')->each(function ($lease) {
            DB::table('leases')->where('id', $lease->id)->update(['portal_token' => Str::lower(Str::random(10))]);
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropUnique(['portal_token']);
            $table->dropColumn('portal_token');
        });
    }
};
