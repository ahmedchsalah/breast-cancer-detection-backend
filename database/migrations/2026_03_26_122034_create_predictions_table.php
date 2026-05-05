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
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_id')->constrained()->cascadeOnDelete();

            $table->enum('status', ['processing', 'completed', 'failed'])->default('processing');

            // --- AI RESULTS ---
            $table->string('predicted_subtype')->nullable(); // e.g., BRCA_LumA
            $table->float('overall_confidence_score')->nullable();

            // --- XAI & SEGMENTATION ---
            $table->string('xai_heatmap_path')->nullable();
            $table->string('patch_data_json_path')->nullable(); // Path to the JSON file with WSI patch coordinates

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
