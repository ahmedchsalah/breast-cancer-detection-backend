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

    /** Maximum queue execution time (seconds). Large SVS on CPU can take 20+ min. */
    public int $timeout = 1800;

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

        // ── Check if we have a slide in R2 or a local .pt file ──────────────
        $wsiUpload    = $prediction->wsiUpload;
        $r2Key        = $wsiUpload?->r2_key;
        $featuresPath = $wsiUpload?->features_path;
        $storageDisk  = config('filesystems.default');
        $hasPtFile    = $featuresPath && Storage::disk($storageDisk)->exists($featuresPath);
        $hasR2Slide   = !empty($r2Key);

        try {
            if ($hasR2Slide) {
                $modalUrl = rtrim((string) config('services.modal.url'), '/');
                if ($modalUrl !== '') {
                    // ── A6 Full Fusion via Modal GPU (fast path) ─────────────
                    $this->callA6ViaModal($modalUrl, $prediction, $clinical, $mode, $r2Key);
                } else {
                    // ── A6 Full Fusion via HF + R2 (legacy fallback) ─────────
                    $this->callA6ViaR2($fastApiBase, $hfToken, $prediction, $clinical, $mode, $r2Key);
                }
            } elseif ($hasPtFile) {
                // ── A6 Full Fusion via local .pt (legacy) ─────────────────────
                $this->callA6($fastApiBase, $hfToken, $prediction, $clinical, $mode,
                              $featuresPath, $storageDisk);
            } else {
                // ── Clinical-only ────────────────────────────────────────
                $modalUrl = rtrim((string) config('services.modal.url'), '/');
                if ($modalUrl !== '') {
                    $this->callClinicalViaModal($modalUrl, $prediction, $clinical, $mode);
                } else {
                    $this->callClinical($fastApiBase, $hfToken, $prediction, $clinical, $mode);
                }
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
    //  A6 Cross-Attention Fusion via Modal GPU (fast path — full pipeline on T4)
    // ─────────────────────────────────────────────────────────────────────────
    private function callA6ViaModal(
        string $modalBase, Prediction $prediction, array $clinical,
        string $mode, string $r2Key
    ): void {
        // Generate a short-lived presigned GET URL for Modal to pull the slide
        $slideUrl = $this->generateR2PresignedGetUrl($r2Key, '+30 minutes');

        $payload = [
            'slide_url'     => $slideUrl,
            'original_name' => $prediction->wsiUpload?->original_name ?? 'slide.svs',
            'clinical'      => $clinical,
            'mode'          => $mode,
            'job_id'        => $prediction->job_id,
        ];

        Log::info("[BReCAI] Calling Modal /predict-a6-from-r2 for prediction #{$prediction->id}");

        $response = Http::timeout(1500)
            ->acceptJson()
            ->asJson()
            ->post(rtrim($modalBase, '/') . '/predict-a6-from-r2', $payload);

        $this->processHttpResponse($response, $prediction, 'a6_fusion_modal');

        // Delete the raw slide from R2 after successful processing
        try {
            Storage::disk('r2')->delete($r2Key);
            Log::info("[BReCAI] Deleted slide from R2: {$r2Key}");
        } catch (\Throwable $e) {
            Log::warning("[BReCAI] Failed to delete R2 slide {$r2Key}: {$e->getMessage()}");
        }
    }

    /** Generate a short-lived presigned GET URL for an R2 key (for Modal to pull). */
    private function generateR2PresignedGetUrl(string $r2Key, string $duration = '+30 minutes'): string
    {
        $s3 = new \Aws\S3\S3Client([
            'version'                 => 'latest',
            'region'                  => 'auto',
            'endpoint'                => config('services.r2.endpoint'),
            'use_path_style_endpoint' => true,
            'credentials'             => [
                'key'    => config('services.r2.access_key'),
                'secret' => config('services.r2.secret_key'),
            ],
        ]);
        $cmd = $s3->getCommand('GetObject', [
            'Bucket' => config('services.r2.bucket'),
            'Key'    => $r2Key,
        ]);
        return (string) $s3->createPresignedRequest($cmd, $duration)->getUri();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  A6 Cross-Attention Fusion via R2 (legacy HF flow)
    // ─────────────────────────────────────────────────────────────────────────
    private function callA6ViaR2(
        string $base, ?string $hfToken,
        Prediction $prediction, array $clinical, string $mode,
        string $r2Key
    ): void {
        $r2Endpoint  = config('services.r2.endpoint');
        $r2Bucket    = config('services.r2.bucket');
        $r2AccessKey = config('services.r2.access_key');
        $r2SecretKey = config('services.r2.secret_key');

        $client = Http::timeout(1500); // 25 min — large SVS slides take time on CPU
        if ($hfToken) {
            $client = $client->withToken($hfToken);
        }

        $response = $client->post("{$base}/predict/a6/from-r2", [
            'r2_endpoint'   => $r2Endpoint,
            'r2_bucket'     => $r2Bucket,
            'r2_key'        => $r2Key,
            'r2_access_key' => $r2AccessKey,
            'r2_secret_key' => $r2SecretKey,
            'clinical_json' => json_encode($clinical),
            'mode'          => $mode,
            'job_id'        => $prediction->job_id,
        ]);

        $this->processHttpResponse($response, $prediction, 'a6_fusion_r2');

        // Delete the raw slide from R2 after successful processing
        try {
            Storage::disk('r2')->delete($r2Key);
            Log::info("[BReCAI] Deleted slide from R2: {$r2Key}");
        } catch (\Throwable $e) {
            Log::warning("[BReCAI] Failed to delete R2 slide {$r2Key}: {$e->getMessage()}");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  A6 Cross-Attention Fusion (legacy local .pt)
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
    //  Clinical-only via Modal GPU (fast path)
    // ─────────────────────────────────────────────────────────────────────────
    private function callClinicalViaModal(
        string $modalBase, Prediction $prediction, array $clinical, string $mode
    ): void {
        $payload = [
            'clinical' => $clinical,
            'mode'     => $mode,
            'job_id'   => $prediction->job_id,
        ];

        Log::info("[BReCAI] Calling Modal /predict-clinical for prediction #{$prediction->id}");

        $response = Http::timeout(120)
            ->acceptJson()
            ->asJson()
            ->post(rtrim($modalBase, '/') . '/predict-clinical', $payload);

        $this->processHttpResponse($response, $prediction, 'clinical_only_modal');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Clinical-only (no WSI / no .pt file) — HF fallback
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
