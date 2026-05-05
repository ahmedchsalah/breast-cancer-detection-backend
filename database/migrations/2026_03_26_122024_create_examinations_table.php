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
        Schema::create('examinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users'); // Doctor who uploaded it

            // --- THE IMAGE ---
            $table->string('wsi_file_path'); // Path to the giant slide file

            // --- CORE SEARCHABLE DATA ---
            $table->string('primary_diagnosis')->nullable();
            $table->string('ajcc_pathologic_stage')->nullable();

            // --- FLEXIBLE AI DATA ---
            // Dumps everything else here (morphology, prior_treatment, etc.)
            $table->json('clinical_features')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('examinations');
    }
};
