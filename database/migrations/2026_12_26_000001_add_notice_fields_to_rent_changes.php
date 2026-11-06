<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rent_changes', function (Blueprint $table) {
            // Days between saving the change and the new rent starting (the notice the tenant got).
            $table->unsignedSmallInteger('notice_days')->nullable();
            // The tenant agreed in writing, so a shorter notice was accepted.
            $table->boolean('tenant_agreed')->default(false);
            $table->timestamp('notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rent_changes', function (Blueprint $table) {
            $table->dropColumn(['notice_days', 'tenant_agreed', 'notified_at']);
        });
    }
};
