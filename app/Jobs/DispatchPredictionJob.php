<?php

namespace App\Jobs;

use App\Models\Prediction;
use App\Models\WsiUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * DispatchPredictionJob
 *
 * Calls the BReCAI FastAPI microservice with the patient's clinical data
 * (and optionally the pre-extracted CONCH feature file) and stores the result.
 *
 * Inference modes:
 *   A6 fusion   — when a WsiUpload with a .pt features file exists
 *   A4 image    — when WsiUpload exists but no .pt features file yet
 *   Clinical    — fallback (no WSI at all, or missing features)
 */
class DispatchPredictionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum queue execution time (seconds). A6 inference can take 3–5 min on CPU. */
    public int $timeout = 600;

    /** Retry on transient network failures. */
    public int $tries = 3;

    public function __construct(public readonly Prediction $prediction) {}

    // ─────────────────────────────────────────────────────────────────────────
    public function handle(): void
    // ─────────────────────────────────────────────────────────────────────────
    {
        $prediction = $this->prediction->fresh(['patient', 'examination', 'wsiUpload']);

        Log::info("[BReCAI] Dispatching prediction #{$prediction->id} job_id={$prediction->job_id}");

        // Mark as processing
        $prediction->update(['status' => Prediction::STATUS_PROCESSING]);

        $fastApiBase  = rtrim(config('services.brecai.url'), '/');
        $internalSecret = config('services.brecai.secret');
        $webhookUrl   = route('internal.predictions.result', ['jobId' => $prediction->job_id]);

        $patient = $prediction->patient;

        // ── Build clinical payload (matches ClinicalFeatures schema) ──────────
        $clinical = [
            'er_status'               => (int) $patient->er_status,
            'pr_status'               => (int) $patient->pr_status,
            'her2_binary'             => (int) $patient->her2_binary,
            'age'                     => (int) $patient->age,
            'stage_num'               => (int) $patient->stage_num,
            'er_status_missing'       => (int) $patient->er_status_missing,
            'pr_status_missing'       => (int) $patient->pr_status_missing,
            'her2_binary_missing'     => 0,  // Not in DB yet — assume known
            'buffa_hypoxia_score'     => $patient->buffa_hypoxia_score,
            'ragnum_hypoxia_score'    => $patient->ragnum_hypoxia_score,
            'winter_hypoxia_score'    => $patient->winter_hypoxia_score,
            'fraction_genome_altered' => $patient->fraction_genome_altered,
            'tumor_break_load'        => null,
            'pr_pct_score'            => null,
            'is_ductal'               => 0.0,
            'is_lobular'              => 0.0,
        ];

        // ── Determine inference mode ──────────────────────────────────────────
        $hasGenomics = $patient->fraction_genome_altered !== null
            || $patient->buffa_hypoxia_score !== null;
        $mode = $hasGenomics ? 'FULL' : 'DZ';

        // ── Check if we have a pre-extracted CONCH features file ─────────────
        $wsiUpload    = $prediction->wsiUpload;
        $featuresPath = $wsiUpload?->features_path;
        $hasPtFile    = $featuresPath && Storage::disk('local')->exists($featuresPath);

        try {
            if ($hasPtFile) {
                // ── A6 Full Fusion ────────────────────────────────────────────
                $this->callA6($fastApiBase, $internalSecret, $webhookUrl,
                              $prediction, $clinical, $mode, $featuresPath);
            } else {
                // ── Clinical-only fallback ────────────────────────────────────
                $this->callClinical($fastApiBase, $internalSecret, $webhookUrl,
                                   $prediction, $clinical, $mode);
            }
        } catch (\Throwable $e) {
            Log::error("[BReCAI] Prediction #{$prediction->id} failed: {$e->getMessage()}");
            $prediction->update([
                'status'         => Prediction::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'completed_at'   => now(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  A6 Cross-Attention Fusion (requires pre-extracted .pt feature file)
    // ─────────────────────────────────────────────────────────────────────────
    private function callA6(
        string $base, string $secret, string $webhookUrl,
        Prediction $prediction, array $clinical, string $mode,
        string $featuresPath
    ): void {
        $ptContent = Storage::disk('local')->get($featuresPath);

        $response = Http::timeout(540)
            ->attach('features_file', $ptContent, 'features.pt')
            ->post("{$base}/predict/a6", [
                'clinical_json' => json_encode($clinical),
                'mode'          => $mode,
                'job_id'        => $prediction->job_id,
                // No webhook_url — we process the response directly here
            ]);

        $this->processHttpResponse($response, $prediction, 'a6_fusion');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Clinical-only (no WSI / no .pt file)
    // ─────────────────────────────────────────────────────────────────────────
    private function callClinical(
        string $base, string $secret, string $webhookUrl,
        Prediction $prediction, array $clinical, string $mode
    ): void {
        $response = Http::timeout(120)
            ->post("{$base}/predict/clinical", [
                'clinical' => $clinical,
                'mode'     => $mode,
                'job_id'   => $prediction->job_id,
            ]);

        $this->processHttpResponse($response, $prediction, 'clinical_only');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Process FastAPI HTTP response and update prediction record
    // ─────────────────────────────────────────────────────────────────────────
    private function processHttpResponse(
        \Illuminate\Http\Client\Response $response,
        Prediction $prediction,
        string $inferenceType
    ): void {
        if (! $response->successful()) {
            throw new \RuntimeException(
                "FastAPI returned HTTP {$response->status()}: " . $response->body()
            );
        }

        $data = $response->json();

        if (($data['status'] ?? '') === 'completed') {
            $prediction->update([
                'status'               => Prediction::STATUS_COMPLETED,
                'is_lum_a'             => $data['is_lum_a'],
                'confidence_lum_a'     => $data['confidence_lum_a'],
                'confidence_non_lum_a' => $data['confidence_non_lum_a'],
                'failure_reason'       => null,
                'completed_at'         => now(),
            ]);
            Log::info(
                "[BReCAI] Prediction #{$prediction->id} completed via {$inferenceType}. " .
                "Label={$data['pred_label']}  prob_luma={$data['prob_luma']}  mode={$data['mode']}"
            );
        } else {
            throw new \RuntimeException(
                "FastAPI returned non-completed status: " . json_encode($data)
            );
        }
    }
}
