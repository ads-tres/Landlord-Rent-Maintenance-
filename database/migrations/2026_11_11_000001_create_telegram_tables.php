<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Telegram chat linked to a landlord (User) or a tenant record (Tenant).
        // A tenant renting from two landlords has two tenant records, so two links to one chat.
        Schema::create('telegram_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->morphs('linkable');
            $table->bigInteger('chat_id')->index();
            $table->bigInteger('telegram_user_id')->nullable();
            $table->string('locale', 2)->default('am');
            $table->timestamp('consented_at')->nullable(); // tenants agree before messages switch to Telegram
            $table->timestamps();

            $table->unique(['linkable_type', 'linkable_id']);
        });

        // Per-chat conversation state (e.g. waiting for a repair description).
        Schema::create('telegram_chats', function (Blueprint $table) {
            $table->bigInteger('chat_id')->primary();
            $table->string('state', 30)->nullable();
            $table->json('state_data')->nullable();
            $table->timestamp('state_expires_at')->nullable();
            $table->string('locale', 2)->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_link_codes', function (Blueprint $table) {
            $table->string('code', 16)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->string('channel', 10)->default('sms')->after('to'); // sms | telegram
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', fn (Blueprint $table) => $table->dropColumn('channel'));
        Schema::dropIfExists('telegram_link_codes');
        Schema::dropIfExists('telegram_chats');
        Schema::dropIfExists('telegram_links');
    }
};
