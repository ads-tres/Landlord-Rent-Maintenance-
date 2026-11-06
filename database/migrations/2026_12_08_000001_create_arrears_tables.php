<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the landlord did about a late tenant: reminder, firm notice, demand letter, plan, note.
        Schema::create('arrears_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who did it
            $table->string('kind', 20);
            $table->unsignedBigInteger('amount_owed'); // santim, at the time
            $table->unsignedSmallInteger('days_overdue');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['lease_id', 'created_at']);
        });

        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('total'); // santim
            $table->date('starts_on');
            $table->string('status', 12)->default('active'); // active | completed | broken | cancelled
            $table->timestamps();
        });

        Schema::create('payment_plan_instalments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_plan_id')->constrained()->cascadeOnDelete();
            $table->date('due_date');
            $table->unsignedBigInteger('amount');
            $table->timestamp('reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_plan_instalments');
        Schema::dropIfExists('payment_plans');
        Schema::dropIfExists('arrears_actions');
    }
};
