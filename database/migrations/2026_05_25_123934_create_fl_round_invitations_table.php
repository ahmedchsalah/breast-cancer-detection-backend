<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fl_round_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fl_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->enum('status', ['pending', 'accepted', 'declined', 'submitted'])->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->float('local_accuracy')->nullable();
            $table->float('local_loss')->nullable();
            $table->string('weights_hash', 128)->nullable();
            $table->timestamps();

            $table->unique(['fl_round_id', 'instructor_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fl_round_invitations');
    }
};
