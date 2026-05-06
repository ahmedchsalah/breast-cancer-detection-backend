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
        Schema::create('xai_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prediction_id')->constrained()->cascadeOnDelete();

            // GradCAM heatmap
            $table->string('heatmap_path')->nullable();
            $table->enum('heatmap_status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending');

            // SHAP
            $table->json('shap_values')->nullable();
            $table->string('shap_plot_path')->nullable();
            $table->enum('shap_status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending');

            // Precomputed top features for fast UI rendering
            $table->json('top_features')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xai_results');
    }
};
