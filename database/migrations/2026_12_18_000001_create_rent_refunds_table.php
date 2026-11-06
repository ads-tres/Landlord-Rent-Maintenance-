<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rent given back to a tenant (e.g. paid ahead for after a move-out); reports subtract it from income.
        Schema::create('rent_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('move_out_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount'); // santim
            $table->date('refunded_on');
            $table->string('reason', 30)->default('prepaid_rent');
            $table->timestamps();
            $table->index(['landlord_id', 'refunded_on']);
        });

        // Move-outs settled before this table existed.
        DB::table('move_outs')->where('status', 'settled')->whereColumn('prepaid', '>', 'prepaid_to_unpaid')->orderBy('id')
            ->each(function ($m) {
                DB::table('rent_refunds')->insert([
                    'landlord_id' => $m->landlord_id, 'lease_id' => $m->lease_id, 'move_out_id' => $m->id,
                    'amount' => $m->prepaid - $m->prepaid_to_unpaid, 'refunded_on' => substr((string) $m->settled_at, 0, 10),
                    'reason' => 'prepaid_rent', 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('rent_refunds');
    }
};
