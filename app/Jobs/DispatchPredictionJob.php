<?php

namespace App\Jobs;

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
                // ── A6 Full Fusion via HF + R2 slide ─────────────────────
                $this->callA6ViaR2($fastApiBase, $hfToken, $prediction, $clinical, $mode, $r2Key);
            } elseif ($hasPtFile) {
                // ── A6 Full Fusion via local .pt ──────────────────────────
                $this->callA6($fastApiBase, $hfToken, $prediction, $clinical, $mode,
                              $featuresPath, $storageDisk);
            } else {
                // ── Clinical-only ────────────────────────────────────────
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
    //  A6 Cross-Attention Fusion via R2 (HF flow — fire-and-forget with webhook)
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

        // Build webhook URL for HF to call back when done
        $webhookUrl = rtrim(config('app.url'), '/') . '/api/internal/predictions/' . $prediction->job_id . '/result';

        // Fire-and-forget: send to HF with a short connect timeout but don't wait for response.
        // HF will call our webhook when processing is complete (10-20 min later).
        $client = Http::timeout(30) // Only wait 30s for the connection to establish
            ->connectTimeout(15)
            ->asMultipart();
        if ($hfToken) {
            $client = $client->withToken($hfToken);
        }

        try {
            $client->post("{$base}/predict/a6/from-r2", [
                ['name' => 'r2_endpoint',   'contents' => $r2Endpoint],
                ['name' => 'r2_bucket',     'contents' => $r2Bucket],
                ['name' => 'r2_key',        'contents' => $r2Key],
                ['name' => 'r2_access_key', 'contents' => $r2AccessKey],
                ['name' => 'r2_secret_key', 'contents' => $r2SecretKey],
                ['name' => 'clinical_json', 'contents' => json_encode($clinical)],
                ['name' => 'mode',          'contents' => $mode],
                ['name' => 'job_id',        'contents' => $prediction->job_id],
                ['name' => 'webhook_url',   'contents' => $webhookUrl],
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Connection timeout is expected — HF accepted the request but we don't wait
            Log::info("[BReCAI] R2 prediction #{$prediction->id} dispatched to HF (fire-and-forget). Webhook will deliver result.");
        }

        // Don't process response here — webhook will handle it.
        // Just mark as processing and return.
        Log::info("[BReCAI] SVS prediction #{$prediction->id} sent to HF. Awaiting webhook callback at: {$webhookUrl}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  A6 Cross-Attention Fusion (local .pt features file)
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

            // ── Auto-conclude examination and create report ───────────────────
            $this->autoConcludeAndReport($prediction, $data);

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

        // R2 heatmap key (uploaded by HF for SVS predictions)
        $heatmapPath = $data['xai_r2_key'] ?? null;

        if (empty($topFeatures) && !$heatmapPath) {
            return; // Nothing to save
        }

        XaiResult::updateOrCreate(
            ['prediction_id' => $prediction->id],
            [
                'top_features'  => $topFeatures,
                'shap_status'   => 'completed',
                'heatmap_path'  => $heatmapPath,
                'heatmap_status'=> $heatmapPath ? 'completed' : (empty($data['patch_attention']) ? 'pending' : 'completed'),
            ]
        );

        Log::info("[BReCAI] XAI data saved for prediction #{$prediction->id}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Auto-conclude examination and generate report after prediction
    // ─────────────────────────────────────────────────────────────────────────
    private function autoConcludeAndReport(Prediction $prediction, array $data): void
    {
        try {
            $examination = $prediction->examination;
            if (! $examination) return;

            // Auto-conclude if still in 'predicted' status
            if ($examination->status === \App\Models\Examination::STATUS_PREDICTED) {
                $label = $data['is_lum_a'] ? 'Luminal A' : 'Non-Luminal A';
                $conf  = round(($data['confidence_lum_a'] ?? 0) * 100, 1);

                $examination->update([
                    'status'            => \App\Models\Examination::STATUS_CONCLUDED,
                    'doctor_conclusion' => "AI Classification: {$label} ({$conf}% confidence). Auto-concluded by BReCAI system.",
                ]);

                Log::info("[BReCAI] Examination #{$examination->id} auto-concluded.");
            }

            // Auto-create draft report if none exists
            $existingReport = \App\Models\Report::where('prediction_id', $prediction->id)->first();
            if (! $existingReport && $examination->status === \App\Models\Examination::STATUS_CONCLUDED) {
                \App\Models\Report::create([
                    'examination_id'  => $examination->id,
                    'prediction_id'   => $prediction->id,
                    'patient_id'      => $prediction->patient_id,
                    'doctor_id'       => $examination->doctor_id,
                    'organization_id' => $prediction->organization_id,
                    'status'          => 'draft',
                    'notes'           => null,
                ]);

                Log::info("[BReCAI] Draft report auto-created for prediction #{$prediction->id}.");
            }
        } catch (\Throwable $e) {
            // Don't fail the job if report creation fails — prediction is already saved
            Log::warning("[BReCAI] Auto-report failed for prediction #{$prediction->id}: {$e->getMessage()}");
        }
    }
}
