<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xai_results', function (Blueprint $table) {
            $table->string('patches_path')->nullable()->after('segmentation_path');
        });
    }

    public function down(): void
    {
        Schema::table('xai_results', function (Blueprint $table) {
            $table->dropColumn('patches_path');
        });
    }
};
