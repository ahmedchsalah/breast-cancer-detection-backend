<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the existing check constraint and recreate it with all required statuses
        DB::statement('ALTER TABLE wsi_uploads DROP CONSTRAINT IF EXISTS wsi_uploads_status_check');
        DB::statement("ALTER TABLE wsi_uploads ADD CONSTRAINT wsi_uploads_status_check CHECK (status IN ('pending', 'processing', 'ready', 'failed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE wsi_uploads DROP CONSTRAINT IF EXISTS wsi_uploads_status_check');
    }
};
