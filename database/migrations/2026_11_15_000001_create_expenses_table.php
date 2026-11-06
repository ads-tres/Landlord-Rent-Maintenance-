<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 30);
            $table->unsignedBigInteger('amount'); // santim
            $table->date('spent_on');
            $table->string('description')->nullable();
            $table->string('vendor')->nullable();
            // Set when the expense was created from a repair, a service or a utility bill.
            $table->nullableMorphs('source');
            $table->timestamps();

            $table->index(['landlord_id', 'spent_on']);
            $table->unique(['source_type', 'source_id']);
        });

        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('cost')->nullable()->after('resolution_note'); // santim
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', fn (Blueprint $table) => $table->dropColumn('cost'));
        Schema::dropIfExists('expenses');
    }
};
