<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add features_path to wsi_uploads table.
     *
     * This column stores the local storage path to the pre-extracted
     * CONCH feature .pt file for a given WSI slide.
     * When populated, DispatchPredictionJob will use /predict/a6 (full fusion).
     * When NULL, the job falls back to /predict/clinical.
     */
    public function up(): void
    {
        Schema::table('wsi_uploads', function (Blueprint $table) {
            $table->string('features_path')->nullable()->after('status')
                  ->comment('Path to pre-extracted CONCH feature .pt file (512-dim, float16)');
            $table->timestamp('features_extracted_at')->nullable()->after('features_path');
        });
    }

    public function down(): void
    {
        Schema::table('wsi_uploads', function (Blueprint $table) {
            $table->dropColumn(['features_path', 'features_extracted_at']);
        });
    }
};
