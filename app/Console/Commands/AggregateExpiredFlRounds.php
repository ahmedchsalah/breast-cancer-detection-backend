<?php

namespace App\Console\Commands;

use App\Models\FlRound;
use App\Models\FlRoundInvitation;
use App\Services\BlockchainHashingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AggregateExpiredFlRounds
 *
 * Scheduled command that checks for FL rounds whose deadline has passed
 * and automatically triggers aggregation for them.
 *
 * This implements the deadline-based aggregation logic:
 *   - Admin creates a round with a deadline (e.g., June 8)
 *   - Instructors can accept, inspect data, and train before this deadline
 *   - When the deadline passes, this command triggers aggregation
 *   - Only submitted contributions are included in aggregation
 *   - If only one instructor submitted, their results are still aggregated
 *     (with criteria adjustments for single-contributor rounds)
 *
 * Scheduled to run every hour in routes/console.php.
 */
class AggregateExpiredFlRounds extends Command
{
    protected $signature   = 'fl:aggregate-expired';
    protected $description = 'Check for FL rounds past their deadline and trigger aggregation';

    public function handle(): int
    {
        $this->info('[FL Aggregator] Checking for expired rounds...');

        // Find all rounds that are past their deadline but not yet aggregated
        $expiredRounds = FlRound::whereIn('status', ['initiated', 'training'])
            ->whereNotNull('deadline')
            ->where('deadline', '<=', now())
            ->get();

        if ($expiredRounds->isEmpty()) {
            $this->info('[FL Aggregator] No expired rounds found.');
            return self::SUCCESS;
        }

        foreach ($expiredRounds as $round) {
            $this->processExpiredRound($round);
        }

        return self::SUCCESS;
    }

    private function processExpiredRound(FlRound $round): void
    {
        $this->info("[FL Aggregator] Processing Round #{$round->round_number} (deadline: {$round->deadline})");

        // Get all submitted invitations
        $submittedInvitations = FlRoundInvitation::where('fl_round_id', $round->id)
            ->where('status', 'submitted')
            ->get();

        // Get all invitations that are still in accepted/training status
        // (they didn't submit in time — mark them as failed to submit)
        $unfinishedInvitations = FlRoundInvitation::where('fl_round_id', $round->id)
            ->whereIn('status', ['accepted', 'training'])
            ->get();

        if ($submittedInvitations->isEmpty()) {
            // No one submitted — mark round as failed
            $round->update([
                'status'   => 'failed',
                'ended_at' => now(),
            ]);

            // Mark unfinished invitations as expired
            foreach ($unfinishedInvitations as $inv) {
                $inv->update(['status' => 'declined']);
            }

            $this->warn("[FL Aggregator] Round #{$round->round_number} failed — no submissions received.");
            Log::warning("[FL Aggregator] Round #{$round->round_number} failed — no submissions received by deadline.");
            return;
        }

        // Mark unfinished invitations as expired (they missed the deadline)
        foreach ($unfinishedInvitations as $inv) {
            $inv->update(['status' => 'declined']);
            Log::info("[FL Aggregator] Instructor {$inv->instructor_id} missed deadline for round {$round->id}");
        }

        // Trigger aggregation via the FL aggregation space
        $this->triggerAggregation($round, $submittedInvitations);
    }

    private function triggerAggregation(FlRound $round, $submittedInvitations): void
    {
        // Mark round as aggregating
        $round->update(['status' => 'aggregating']);

        // Build contributions list
        $contributions = $submittedInvitations->map(function ($inv) {
            return [
                'round_id'          => $inv->fl_round_id,
                'invitation_id'     => $inv->id,
                'instructor_id'    => $inv->instructor_id,
                'organization_id'  => $inv->instructor->organization_id ?? 0,
                'local_accuracy'   => $inv->local_accuracy,
                'local_loss'       => $inv->local_loss,
                'local_sample_size'=> $inv->local_sample_size ?? 0,
                'weights_r2_key'   => $inv->weights_r2_key ?? '',
                'weights_hash'     => $inv->weights_hash ?? '',
                'modality'         => 'FULL',
            ];
        })->values()->toArray();

        // Call the FL aggregation space
        $aggUrl = rtrim(config('services.fl_aggregation.url'), '/');

        try {
            $response = Http::timeout(120)->post("{$aggUrl}/aggregate", [
                'round_id'        => $round->id,
                'modality'        => $round->modality,
                'contributions'   => $contributions,
                'webhook_url'     => url('/api/internal/fl/aggregation-result'),
                'internal_secret' => config('services.fl_aggregation.secret'),
            ]);

            if ($response->successful()) {
                $result = $response->json();

                // Record blockchain aggregation block
                if (!empty($result['aggregated_weights_hash'])) {
                    $contributionHashes = $submittedInvitations->pluck('weights_hash')->filter()->toArray();
                    BlockchainHashingService::createAggregationBlock(
                        $round->id,
                        $result['aggregated_weights_hash'],
                        $contributionHashes,
                        $round->aggregation_method,
                        [
                            'n_contributions' => $result['n_contributions'] ?? count($contributions),
                            'avg_accuracy'    => $result['global_accuracy'] ?? null,
                        ]
                    );
                }

                // Update round with aggregation results
                $round->update([
                    'status'                    => 'completed',
                    'global_accuracy'           => $result['global_accuracy'] ?? null,
                    'global_loss'               => $result['global_loss'] ?? null,
                    'aggregated_weights_r2_key' => $result['aggregated_weights_r2_key'] ?? null,
                    'aggregated_weights_hash'   => $result['aggregated_weights_hash'] ?? null,
                    'ended_at'                  => now(),
                ]);

                $this->info("[FL Aggregator] Round #{$round->round_number} aggregated successfully. Accuracy: " . ($result['global_accuracy'] ?? 'N/A'));
                Log::info("[FL Aggregator] Round #{$round->round_number} aggregated. Accuracy: " . ($result['global_accuracy'] ?? 'N/A'));
            } else {
                Log::error('[FL Aggregator] Aggregation service error: ' . $response->body());
                $this->error("[FL Aggregator] Aggregation failed for Round #{$round->round_number}");
            }
        } catch (\Throwable $e) {
            Log::error('[FL Aggregator] Exception: ' . $e->getMessage());
            $this->error("[FL Aggregator] Failed: {$e->getMessage()}");
        }
    }
}
