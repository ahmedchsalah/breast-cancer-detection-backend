<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('patient_identifier')->unique(); // e.g., TCGA-E2-A1IU

            $table->boolean('er_status')->comment('1 = Positive, 0 = Negative');
            $table->boolean('pr_status')->comment('1 = Positive, 0 = Negative');
            $table->boolean('her2_binary')->comment('1 = Positive, 0 = Negative');
            $table->integer('age')->comment('Age of the patient');
            $table->integer('stage_num')->comment('Tumor stage: 1, 2, 3, or 4');
            $table->boolean('er_status_missing')->default(false);
            $table->boolean('pr_status_missing')->default(false);
            $table->float('fraction_genome_altered')->nullable()->comment('Float between 0.0 and 1.0');
            $table->float('buffa_hypoxia_score')->nullable();
            $table->float('ragnum_hypoxia_score')->nullable();
            $table->float('winter_hypoxia_score')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
