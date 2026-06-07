<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->float('tumor_break_load')->nullable()->after('winter_hypoxia_score')
                ->comment('Tumor Break Load — genomic feature used by the A6 model when present');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('tumor_break_load');
        });
    }
};
