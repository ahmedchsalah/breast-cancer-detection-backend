<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (Schema::hasColumn('plans', 'max_users')) {
                $table->dropColumn('max_users');
            }
            if (Schema::hasColumn('plans', 'max_storage_gb')) {
                $table->dropColumn('max_storage_gb');
            }
        });

        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'description')) {
                $table->text('description')->nullable()->after('slug');
            }
            if (!Schema::hasColumn('plans', 'max_doctors')) {
                $table->integer('max_doctors')->default(2)->after('price');
            }
            if (!Schema::hasColumn('plans', 'max_predictions_per_month')) {
                $table->integer('max_predictions_per_month')->default(5)->after('max_doctors');
            }
            if (!Schema::hasColumn('plans', 'fl_contribution_allowed')) {
                $table->boolean('fl_contribution_allowed')->default(false)->after('max_predictions_per_month');
            }
            if (!Schema::hasColumn('plans', 'instructor_allowed')) {
                $table->boolean('instructor_allowed')->default(false)->after('fl_contribution_allowed');
            }
            if (!Schema::hasColumn('plans', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('instructor_allowed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'description', 'max_doctors', 'max_predictions_per_month',
                'fl_contribution_allowed', 'instructor_allowed', 'is_active',
            ]);
            $table->integer('max_users')->default(1);
            $table->integer('max_storage_gb')->default(5);
        });
    }
};
