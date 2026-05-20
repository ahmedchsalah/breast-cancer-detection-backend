<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Ensures wsi_uploads has r2_key column and correct status check constraint.
 * Safe to run multiple times — checks existence before modifying.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add r2_key column if it doesn't exist
        if (!Schema::hasColumn('wsi_uploads', 'r2_key')) {
            Schema::table('wsi_uploads', function (Blueprint $table) {
                $table->string('r2_key')->nullable()->after('features_path');
            });
        }

        // Fix status check constraint to include 'pending'
        // Drop existing constraint and recreate with all valid values
        DB::statement('ALTER TABLE wsi_uploads DROP CONSTRAINT IF EXISTS wsi_uploads_status_check');
        DB::statement("ALTER TABLE wsi_uploads ADD CONSTRAINT wsi_uploads_status_check CHECK (status IN ('pending', 'processing', 'ready', 'failed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE wsi_uploads DROP CONSTRAINT IF EXISTS wsi_uploads_status_check');
        if (Schema::hasColumn('wsi_uploads', 'r2_key')) {
            Schema::table('wsi_uploads', function (Blueprint $table) {
                $table->dropColumn('r2_key');
            });
        }
    }
};
