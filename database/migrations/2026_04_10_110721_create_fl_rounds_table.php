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
        Schema::create('fl_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_model_id')->constrained()->cascadeOnDelete();
            $table->integer('round_number');
            $table->enum('status', ['initiated', 'training', 'aggregating', 'completed', 'failed']);
            $table->float('global_accuracy')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fl_rounds');
    }
};
