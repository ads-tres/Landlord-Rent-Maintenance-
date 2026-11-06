<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('move_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lease_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('moved_out_on');
            $table->text('condition_notes')->nullable();
            $table->boolean('keys_returned')->default(false);
            $table->string('status', 10)->default('draft'); // draft | settled
            // Filled in when settled. Amounts in santim.
            $table->unsignedBigInteger('deposit_held')->default(0);
            $table->unsignedBigInteger('deposit_applied')->default(0); // to unpaid rent and utilities
            $table->unsignedBigInteger('deposit_kept')->default(0);    // for damage, cleaning, keys...
            $table->unsignedBigInteger('refund_amount')->default(0);
            $table->unsignedBigInteger('balance_owed')->default(0);    // tenant still owes, beyond the deposit
            $table->string('refund_method', 20)->nullable();
            $table->string('refund_reference', 120)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('statement_token', 10)->unique();
            $table->timestamps();
        });

        Schema::create('move_out_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('move_out_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->string('description', 250)->nullable();
            $table->unsignedBigInteger('amount');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('move_out_deductions');
        Schema::dropIfExists('move_outs');
    }
};
