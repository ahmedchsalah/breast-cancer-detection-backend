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
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();

            // The email address the invitation was sent to
            $table->string('email')->unique();

            // A secure unique token for the registration URL
            $table->string('token')->unique();

            // The organization they are being invited to join
            $table->foreignId('organization_id')
                ->constrained()
                ->onDelete('cascade');

            // The specific role they will receive (doctor or instructor)
            $table->enum('role', ['doctor', 'instructor']);

            // When the link should stop working (e.g., now + 48 hours)
            $table->timestamp('expires_at');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
