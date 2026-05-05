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
        Schema::create('fl_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fl_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->integer('local_sample_size'); // How many WSIs they trained on
            $table->float('local_accuracy_before');
            $table->float('local_accuracy_after');
            $table->string('weights_update_path'); // Path to the local gradients file
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fl_contributions');
    }
};
