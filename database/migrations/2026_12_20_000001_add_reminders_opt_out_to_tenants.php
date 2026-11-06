<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // The tenant asked not to get rent reminders (receipts and notices still go out).
            $table->boolean('reminders_opt_out')->default(false)->after('address');
        });
        Schema::table('sms_messages', function (Blueprint $table) {
            // Held until morning when it was created during quiet hours.
            $table->timestamp('send_after')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('reminders_opt_out'));
        Schema::table('sms_messages', fn (Blueprint $t) => $t->dropColumn('send_after'));
    }
};
