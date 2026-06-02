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
            $table->timestamp('deadline')->nullable()->after('ended_at');

            // Aggregation method: fedavg_weighted, fedavg_accuracy_weighted, robust
            $table->string('aggregation_method', 50)->default('fedavg_weighted')->after('deadline');

            // Gemini-recommended hyperparams for this round (JSON)
            $table->json('recommended_hyperparams')->nullable()->after('aggregation_method');

            // Blockchain receipt: proof of aggregation integrity (JSON)
            $table->json('blockchain_receipt')->nullable()->after('recommended_hyperparams');

            // Aggregated weights R2 key
            $table->string('aggregated_weights_r2_key', 500)->nullable()->after('blockchain_receipt');

            // Aggregated weights hash (blockchain)
            $table->string('aggregated_weights_hash', 128)->nullable()->after('aggregated_weights_r2_key');

            // Global loss (aggregated)
            $table->float('global_loss')->nullable()->after('global_accuracy');
        });

        // ── fl_round_invitations ────────────────────────────────────────────────
        Schema::table('fl_round_invitations', function (Blueprint $table) {
            // R2 key where the instructor uploaded their trained weights
            $table->string('weights_r2_key', 500)->nullable()->after('weights_hash');

            // Hyperparameters the instructor actually used (JSON)
            $table->json('hyperparams_used')->nullable()->after('weights_r2_key');

            // Number of samples used for local training
            $table->integer('local_sample_size')->nullable()->after('hyperparams_used');

            // Timestamps for async training flow
            $table->timestamp('data_inspected_at')->nullable()->after('local_sample_size');
            $table->timestamp('training_started_at')->nullable()->after('data_inspected_at');
            $table->timestamp('training_completed_at')->nullable()->after('training_started_at');

            // New status: 'training' — instructor accepted and is currently training
            // We alter the enum to add 'training' status
        });

        // Add 'training' to the invitation status enum
        DB::statement("ALTER TABLE fl_round_invitations MODIFY COLUMN status ENUM('pending', 'accepted', 'training', 'declined', 'submitted') NOT NULL DEFAULT 'pending'");

        // ── fl_contributions ─────────────────────────────────────────────────────
        Schema::table('fl_contributions', function (Blueprint $table) {
            $table->string('weights_hash', 128)->nullable()->after('weights_update_path');
            $table->string('aggregation_method', 50)->nullable()->after('weights_hash');
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

        DB::statement("ALTER TABLE fl_round_invitations MODIFY COLUMN status ENUM('pending', 'accepted', 'declined', 'submitted') NOT NULL DEFAULT 'pending'");

        Schema::table('fl_contributions', function (Blueprint $table) {
            $table->dropColumn(['weights_hash', 'aggregation_method']);
        });
    }
};
