<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fl_rounds', function (Blueprint $table) {
            // Make ai_model_id optional — rounds can now be modality-based
            $table->foreignId('ai_model_id')->nullable()->change();

            // Add modality field — determines which models benefit from contributions
            // multimodal: A6 (image+clinical), image_only: A4 (image), clinical_only: A1 (clinical)
            // open: any modality accepted
            $table->string('modality')->default('open')->after('ai_model_id');

            // Human-readable title for the round
            $table->string('title')->nullable()->after('modality');

            // Description / instructions for instructors
            $table->text('description')->nullable()->after('title');

            // Minimum samples required per contributor
            $table->integer('min_samples')->default(20)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('fl_rounds', function (Blueprint $table) {
            $table->dropColumn(['modality', 'title', 'description', 'min_samples']);
            $table->foreignId('ai_model_id')->nullable(false)->change();
        });
    }
};
