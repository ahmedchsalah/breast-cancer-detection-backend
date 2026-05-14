<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'subscription_id')) {
                $table->foreignId('subscription_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            }
            if (!Schema::hasColumn('payments', 'plan_id')) {
                $table->foreignId('plan_id')->nullable()->after('subscription_id')->constrained()->nullOnDelete();
            }
            if (!Schema::hasColumn('payments', 'chargily_checkout_id')) {
                $table->string('chargily_checkout_id')->nullable()->unique()->after('transaction_id');
            }
            if (!Schema::hasColumn('payments', 'checkout_url')) {
                $table->string('checkout_url', 500)->nullable()->after('chargily_checkout_id');
            }
            if (!Schema::hasColumn('payments', 'duration_months')) {
                $table->unsignedTinyInteger('duration_months')->default(1)->after('checkout_url');
            }
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
