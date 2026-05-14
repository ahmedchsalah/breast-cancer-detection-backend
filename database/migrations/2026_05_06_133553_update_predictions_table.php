<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('predictions')) {
            Schema::create('predictions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('examination_id')->constrained()->cascadeOnDelete();
                $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
                $table->foreignId('ai_model_id')->constrained()->cascadeOnDelete();
                $table->foreignId('wsi_upload_id')->nullable()->constrained()->nullOnDelete();

                $table->boolean('is_lum_a')->nullable();
                $table->float('confidence_lum_a')->nullable();
                $table->float('confidence_non_lum_a')->nullable();

                $table->json('clinical_input_snapshot')->nullable();

                $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                    ->default('pending');
                $table->string('job_id')->nullable()->unique();
                $table->text('failure_reason')->nullable();
                $table->timestamp('completed_at')->nullable();

                $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();

                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
