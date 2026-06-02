<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add deadline, hyperparameters, and blockchain fields to FL tables.
     *
     * Key changes:
     *  - fl_rounds: deadline (when aggregation should auto-trigger),
     *              aggregation_method, recommended_hyperparams (Gemini-suggested),
     *              blockchain_receipt (integrity proof)
     *  - fl_round_invitations: weights_r2_key (path to contributed weights in R2),
     *                         hyperparams_used (what the instructor actually used),
     *                         local_sample_size, data_inspected_at,
     *                         training_started_at, training_completed_at
     *  - fl_contributions: weights_hash (blockchain hash), aggregation_method
     */
    public function up(): void
    {
        // ── fl_rounds ──────────────────────────────────────────────────────────
        Schema::table('fl_rounds', function (Blueprint $table) {
            // Deadline: after this datetime, aggregation is triggered automatically
            $table->timestamp('deadline')->nullable();

            // Aggregation method: fedavg_weighted, fedavg_accuracy_weighted, robust
            $table->string('aggregation_method', 50)->default('fedavg_weighted');

            // Gemini-recommended hyperparams for this round (JSON)
            $table->json('recommended_hyperparams')->nullable();

            // Blockchain receipt: proof of aggregation integrity (JSON)
            $table->json('blockchain_receipt')->nullable();

            // Aggregated weights R2 key
            $table->string('aggregated_weights_r2_key', 500)->nullable();

            // Aggregated weights hash (blockchain)
            $table->string('aggregated_weights_hash', 128)->nullable();

            // Global loss (aggregated)
            $table->float('global_loss')->nullable();
        });

        // ── fl_round_invitations ────────────────────────────────────────────────
        Schema::table('fl_round_invitations', function (Blueprint $table) {
            // R2 key where the instructor uploaded their trained weights
            $table->string('weights_r2_key', 500)->nullable();

            // Hyperparameters the instructor actually used (JSON)
            $table->json('hyperparams_used')->nullable();

            // Number of samples used for local training
            $table->integer('local_sample_size')->nullable();

            // Timestamps for async training flow
            $table->timestamp('data_inspected_at')->nullable();
            $table->timestamp('training_started_at')->nullable();
            $table->timestamp('training_completed_at')->nullable();

            // New status: 'training' — instructor accepted and is currently training
            // We alter the enum to add 'training' status
        });

        // Add 'training' to the invitation status enum
        // PostgreSQL uses ALTER TYPE ... ADD VALUE (not MySQL's MODIFY COLUMN)
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            // PostgreSQL: the enum type name follows the pattern {table}_{column}_type
            DB::statement("ALTER TABLE fl_round_invitations ALTER COLUMN status TYPE varchar(50)");
            DB::statement("ALTER TABLE fl_round_invitations ALTER COLUMN status SET DEFAULT 'pending'");
        } else {
            // MySQL
            DB::statement("ALTER TABLE fl_round_invitations MODIFY COLUMN status ENUM('pending', 'accepted', 'training', 'declined', 'submitted') NOT NULL DEFAULT 'pending'");
        }

        // ── fl_contributions ─────────────────────────────────────────────────────
        Schema::table('fl_contributions', function (Blueprint $table) {
            $table->string('weights_hash', 128)->nullable();
            $table->string('aggregation_method', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fl_rounds', function (Blueprint $table) {
            $table->dropColumn([
                'deadline',
                'aggregation_method',
                'recommended_hyperparams',
                'blockchain_receipt',
                'aggregated_weights_r2_key',
                'aggregated_weights_hash',
                'global_loss',
            ]);
        });

        Schema::table('fl_round_invitations', function (Blueprint $table) {
            $table->dropColumn([
                'weights_r2_key',
                'hyperparams_used',
                'local_sample_size',
                'data_inspected_at',
                'training_started_at',
                'training_completed_at',
            ]);
        });

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE fl_round_invitations ALTER COLUMN status TYPE varchar(50)");
            DB::statement("ALTER TABLE fl_round_invitations ALTER COLUMN status SET DEFAULT 'pending'");
        } else {
            DB::statement("ALTER TABLE fl_round_invitations MODIFY COLUMN status ENUM('pending', 'accepted', 'declined', 'submitted') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('fl_contributions', function (Blueprint $table) {
            $table->dropColumn(['weights_hash', 'aggregation_method']);
        });
    }
};
