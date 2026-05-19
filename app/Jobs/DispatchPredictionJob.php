<?php

namespace App\Jobs;

use App\Models\AiModel;
use App\Models\Prediction;
use App\Models\XaiResult;
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
 * Inference modes (selected automatically):
 *   A6 fusion   — when a WsiUpload with a .pt features file exists  ← best accuracy
 *   Clinical    — fallback when no WSI features are available
 *
 * After a completed prediction, the XAI attention weights (top-patch importances
 * + clinical feature importances) are saved to the xai_results table.
 */
class DispatchPredictionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum queue execution time (seconds). A6 on CPU takes 3–5 min. */
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

        $fastApiBase    = rtrim(config('services.brecai.url'), '/');
        $internalSecret = config('services.brecai.secret');
        $hfToken        = config('services.brecai.hf_token');

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
            'her2_binary_missing'     => 0,
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

        // Use the default configured disk (local in dev, S3/cloud in production)
        $storageDisk  = config('filesystems.default');
        $hasPtFile    = $featuresPath && Storage::disk($storageDisk)->exists($featuresPath);

        try {
            if ($hasPtFile) {
                // ── A6 Full Fusion ────────────────────────────────────────────
                $this->callA6($fastApiBase, $hfToken, $prediction, $clinical, $mode,
                              $featuresPath, $storageDisk);
            } else {
                // ── Clinical-only fallback ────────────────────────────────────
                // Clinical inference takes ~30s — run synchronously, no queue needed
                $this->callClinical($fastApiBase, $hfToken, $prediction, $clinical, $mode);
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
    //  A6 Cross-Attention Fusion
    // ─────────────────────────────────────────────────────────────────────────
    private function callA6(
        string $base, ?string $hfToken,
        Prediction $prediction, array $clinical, string $mode,
        string $featuresPath, string $disk
    ): void {
        $ptContent = Storage::disk($disk)->get($featuresPath);

        $client = Http::timeout(540);
        if ($hfToken) {
            $client = $client->withToken($hfToken);
        }

        $response = $client
            ->attach('features_file', $ptContent, 'features.pt')
            ->post("{$base}/predict/a6", [
                'clinical_json' => json_encode($clinical),
                'mode'          => $mode,
                'job_id'        => $prediction->job_id,
            ]);

        $this->processHttpResponse($response, $prediction, 'a6_fusion');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Clinical-only (no WSI / no .pt file)
    // ─────────────────────────────────────────────────────────────────────────
    private function callClinical(
        string $base, ?string $hfToken,
        Prediction $prediction, array $clinical, string $mode
    ): void {
        $client = Http::timeout(120);
        if ($hfToken) {
            $client = $client->withToken($hfToken);
        }

        $response = $client->post("{$base}/predict/clinical", [
            'clinical' => $clinical,
            'mode'     => $mode,
            'job_id'   => $prediction->job_id,
        ]);

        $this->processHttpResponse($response, $prediction, 'clinical_only');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Process FastAPI HTTP response, update prediction, save XAI
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

            // ── Save XAI data if returned ─────────────────────────────────────
            $this->saveXaiData($prediction, $data, $inferenceType);

        } else {
            throw new \RuntimeException(
                "FastAPI returned non-completed status: " . json_encode($data)
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Save XAI results returned by FastAPI
    // ─────────────────────────────────────────────────────────────────────────
    private function saveXaiData(Prediction $prediction, array $data, string $inferenceType): void
    {
        // Build top_features combining patch attention + clinical importances
        $topFeatures = [];

        // Clinical feature importances (returned by both A6 and clinical-only)
        if (! empty($data['clinical_importances'])) {
            $topFeatures['clinical'] = $data['clinical_importances'];
        }

        // Patch attention weights (returned only by A6)
        if (! empty($data['patch_attention'])) {
            $topFeatures['top_patches'] = $data['patch_attention'];
        }

        // Gate weights — how much image vs clinical contributed
        if (isset($data['gate_img'], $data['gate_clin'])) {
            $topFeatures['fusion_gate'] = [
                'image_weight'    => round($data['gate_img'], 4),
                'clinical_weight' => round($data['gate_clin'], 4),
            ];
        }

        if (empty($topFeatures)) {
            return; // Nothing to save
        }

        XaiResult::updateOrCreate(
            ['prediction_id' => $prediction->id],
            [
                'top_features'  => $topFeatures,
                'shap_status'   => 'completed',
                'heatmap_status'=> empty($data['patch_attention']) ? 'pending' : 'completed',
            ]
        );

        Log::info("[BReCAI] XAI data saved for prediction #{$prediction->id}");
    }
}
