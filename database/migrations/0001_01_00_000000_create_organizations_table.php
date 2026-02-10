<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained();
            $table->string('name');
            $table->string('type');
            $table->string('code')->unique();
            $table->string('address')->nullable();
            $table->string('contact_email');

            // Status
            $table->string('subscription_status')->default('trial');
            $table->date('subscription_ends_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
