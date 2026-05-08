<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();

            // Identity
            $table->string('name');         // e.g., "BReCAI-A6 Cross-Attention Fusion"
            $table->string('slug')->unique(); // e.g., "a6_fusion" — used as FK reference
            $table->string('version');       // e.g., "v12.0"

            // What the model does
            $table->string('inference_type'); // 'a6_fusion' | 'a4_image_only' | 'clinical_only'
            $table->text('description')->nullable();

            // Performance metrics (from training/validation — static, set by seeder)
            $table->float('auc')->nullable();           // ROC-AUC
            $table->float('accuracy')->nullable();      // Balanced accuracy
            $table->float('sensitivity')->nullable();   // Recall for LumA class
            $table->float('specificity')->nullable();   // Recall for Non-LumA class
            $table->float('f1_score')->nullable();
            $table->integer('n_checkpoints')->default(0); // Number of ensemble checkpoints
            $table->float('threshold')->nullable();     // Decision threshold

            // Extra metadata (architecture info, dataset, etc.)
            $table->json('metadata')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
