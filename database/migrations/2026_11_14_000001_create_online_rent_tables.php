<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Chapa online rent: landlords' payout accounts (Chapa sub-accounts) and checkout transactions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('bank_code', 50);
            $table->string('bank_name')->nullable();
            $table->string('account_name');
            $table->text('account_number'); // encrypted cast
            $table->string('chapa_subaccount_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending | active | failed
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('online_rent_enabled')->default(false)->after('sms_reminders');
        });

        Schema::table('leases', function (Blueprint $table) {
            // Tenant's online payment page (/pay/{token}).
            $table->string('pay_token', 16)->nullable()->unique()->after('repair_token');
        });
        DB::table('leases')->whereNull('pay_token')->orderBy('id')->each(function ($lease) {
            DB::table('leases')->where('id', $lease->id)->update(['pay_token' => Str::lower(Str::random(10))]);
        });

        Schema::create('chapa_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('tx_ref', 64)->unique();
            $table->unsignedBigInteger('amount'); // santim
            $table->unsignedBigInteger('fee')->default(0); // our split fee, santim
            $table->string('status', 20)->default('pending'); // pending | success | failed
            $table->text('checkout_url')->nullable();
            $table->string('chapa_reference')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapa_transactions');
        Schema::table('leases', function (Blueprint $table) {
            $table->dropUnique(['pay_token']);
            $table->dropColumn('pay_token');
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('online_rent_enabled'));
        Schema::dropIfExists('payout_accounts');
    }
};
