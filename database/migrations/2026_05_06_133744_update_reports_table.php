<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('reports')) {
            Schema::create('reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('examination_id')->constrained()->cascadeOnDelete();
                $table->foreignId('prediction_id')->constrained()->cascadeOnDelete();
                $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
                $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

                $table->string('file_path')->nullable();
                $table->string('file_name')->nullable();
                $table->text('notes')->nullable();
                $table->enum('status', ['draft', 'final'])->default('draft');

                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
