<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('asking_rent'); // santim
            $table->date('available_from');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft'); // draft | live | closed | suspended
            $table->string('slug', 16)->unique();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedSmallInteger('report_count')->default(0);
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('phone', 20);
            $table->timestamp('phone_verified_at')->nullable();
            $table->unsignedTinyInteger('household_size')->nullable();
            $table->date('move_in_date')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('new'); // new | shortlisted | rejected | approved
            $table->foreignId('lease_id')->nullable()->constrained()->nullOnDelete(); // draft lease on approval
            $table->timestamp('delete_after')->nullable();
            $table->timestamps();
            $table->unique(['listing_id', 'phone']);
            $table->index('delete_after');
        });

        // Directive 7/2024 lists keeping a house unrented for over 6 months as a violation:
        // track when each unit became vacant to remind the landlord at 3 and 5 months.
        Schema::table('units', function (Blueprint $table) {
            $table->timestamp('vacant_since')->nullable()->after('status');
            $table->unsignedTinyInteger('vacancy_reminded_months')->default(0)->after('vacant_since');
        });
        DB::table('units')->where('status', 'vacant')->update(['vacant_since' => now()]);
    }

    public function down(): void
    {
        Schema::table('units', fn (Blueprint $table) => $table->dropColumn(['vacant_since', 'vacancy_reminded_months']));
        Schema::dropIfExists('applications');
        Schema::dropIfExists('listings');
    }
};
