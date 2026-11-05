<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_signups', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('unit_count');
            $table->string('current_tracking')->nullable();
            $table->text('biggest_problem')->nullable();
            $table->unsignedInteger('monthly_price_birr')->nullable();
            $table->string('locale', 2)->default('am');
            $table->string('source')->nullable(); // e.g. telegram post id, utm_source
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_signups');
    }
};
