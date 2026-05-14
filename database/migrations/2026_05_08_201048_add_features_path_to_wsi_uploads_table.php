<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wsi_uploads', function (Blueprint $table) {
            if (!Schema::hasColumn('wsi_uploads', 'features_path')) {
                $table->string('features_path')->nullable()->after('status')
                      ->comment('Path to pre-extracted CONCH feature .pt file');
            }
            if (!Schema::hasColumn('wsi_uploads', 'features_extracted_at')) {
                $table->timestamp('features_extracted_at')->nullable()->after('features_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wsi_uploads', function (Blueprint $table) {
            $table->dropColumn(['features_path', 'features_extracted_at']);
        });
    }
};
