<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('lease_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            // Short random token for the public receipt link sent by SMS (/r/{token}).
            $table->string('receipt_token', 16)->nullable()->unique()->after('source');
            $table->text('notes')->nullable()->after('reference');
            $table->index(['landlord_id', 'paid_at']);
            $table->index(['landlord_id', 'method', 'reference']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('sms_reminders')->default(true)->after('calendar_pref');
        });

        // Every SMS sent, to count costs per landlord and avoid duplicate reminders.
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('to', 20);
            $table->string('kind', 30); // receipt | reminder_before | reminder_due
            $table->nullableMorphs('related');
            $table->text('body');
            $table->unsignedTinyInteger('segments')->default(1);
            $table->string('status', 20)->default('queued'); // queued | sent | failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['landlord_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sms_reminders');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['landlord_id', 'paid_at']);
            $table->dropIndex(['landlord_id', 'method', 'reference']);
            $table->dropConstrainedForeignId('lease_id');
            $table->dropUnique(['receipt_token']);
            $table->dropColumn(['receipt_token', 'notes']);
        });
    }
};
