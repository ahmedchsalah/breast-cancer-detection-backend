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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            // Plan Details
            $table->string('name'); // e.g., "Basic", "Pro"
            $table->string('slug')->unique(); // e.g., "basic", "pro"
            $table->decimal('price', 10, 2); // 0.00 or 5000.00

            // Features / Limits
            $table->integer('max_users')->default(1);
            $table->integer('max_storage_gb')->default(5);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
