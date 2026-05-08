<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            // Remove the wrong old column
            if (Schema::hasColumn('ai_models', 'file_path')) {
                $table->dropColumn('file_path');
            }

            // Add new identity columns
            if (! Schema::hasColumn('ai_models', 'slug')) {
                $table->string('slug')->unique()->after('name');
            }
            if (! Schema::hasColumn('ai_models', 'inference_type')) {
                $table->string('inference_type')->after('version');
            }
            if (! Schema::hasColumn('ai_models', 'description')) {
                $table->text('description')->nullable()->after('inference_type');
            }

            // Add performance metric columns
            if (! Schema::hasColumn('ai_models', 'auc')) {
                $table->float('auc')->nullable()->after('description');
            }
            if (! Schema::hasColumn('ai_models', 'accuracy')) {
                $table->float('accuracy')->nullable()->after('auc');
            }
            if (! Schema::hasColumn('ai_models', 'sensitivity')) {
                $table->float('sensitivity')->nullable()->after('accuracy');
            }
            if (! Schema::hasColumn('ai_models', 'specificity')) {
                $table->float('specificity')->nullable()->after('sensitivity');
            }
            if (! Schema::hasColumn('ai_models', 'f1_score')) {
                $table->float('f1_score')->nullable()->after('specificity');
            }
            if (! Schema::hasColumn('ai_models', 'n_checkpoints')) {
                $table->integer('n_checkpoints')->default(0)->after('f1_score');
            }
            if (! Schema::hasColumn('ai_models', 'threshold')) {
                $table->float('threshold')->nullable()->after('n_checkpoints');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropColumn([
                'slug', 'inference_type', 'description',
                'auc', 'accuracy', 'sensitivity', 'specificity',
                'f1_score', 'n_checkpoints', 'threshold',
            ]);
            $table->string('file_path')->nullable();
        });
    }
};
