<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // FK to the subscription this payment activates
            $table->foreignId('subscription_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            // FK to the plan being purchased
            $table->foreignId('plan_id')->nullable()->after('subscription_id')->constrained()->nullOnDelete();
            // Chargily's own checkout ID — used to look up this payment in webhook
            $table->string('chargily_checkout_id')->nullable()->unique()->after('transaction_id');
            // The URL Chargily gives us to redirect the customer
            $table->string('checkout_url', 500)->nullable()->after('chargily_checkout_id');
            // Duration of the plan in months purchased
            $table->unsignedTinyInteger('duration_months')->default(1)->after('checkout_url');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
            $table->dropForeign(['plan_id']);
            $table->dropColumn(['subscription_id', 'plan_id', 'chargily_checkout_id', 'checkout_url', 'duration_months']);
        });
    }
};
