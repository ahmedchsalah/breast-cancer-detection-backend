<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('examinations')) {
            Schema::create('examinations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
                $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

                $table->text('chief_complaint')->nullable();
                $table->text('clinical_notes')->nullable();
                $table->text('doctor_conclusion')->nullable();

                $table->enum('status', ['draft', 'submitted', 'predicted', 'concluded'])
                    ->default('draft');

                $table->timestamp('examined_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('examinations');
    }
};
