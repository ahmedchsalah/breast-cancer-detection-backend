<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Models\Prediction;
use App\Models\XaiResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Called by the FastAPI microservice after a prediction job is completed.
 * This route should be protected by a shared secret key (not Sanctum).
 */
class PredictionWebhookController extends Controller
{
    /**
     * Receive the prediction result from FastAPI and persist it.
     *
     * FastAPI should POST to: POST /internal/predictions/{job_id}/result
     *
     * Expected payload:
     * {
     *   "status": "completed" | "failed",
     *   "is_lum_a": true | false,
     *   "confidence_lum_a": 0.91,
     *   "confidence_non_lum_a": 0.09,
     *   "failure_reason": null | "string",
     *   "xai": {
     *     "heatmap_path": "storage/...",
     *     "heatmap_status": "ready" | "pending" | "failed",
     *     "shap_plot_path": "storage/...",
     *     "shap_status": "ready" | "pending" | "failed",
     *     "shap_values": { ... },
     *     "top_features": [ ... ]
     *   }
     * }
     */
    public function handle(Request $request, string $jobId): JsonResponse
    {
        // Validate shared secret
        $secret = $request->header('X-Internal-Secret');
        if ($secret !== config('app.internal_webhook_secret')) {
            Log::warning("Webhook called with invalid secret. Job: {$jobId}");
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $prediction = Prediction::where('job_id', $jobId)->first();

        if (!$prediction) {
            Log::error("Webhook: No prediction found for job_id: {$jobId}");
            return response()->json(['message' => 'Prediction not found.'], 404);
        }

        if ($prediction->status === Prediction::STATUS_COMPLETED) {
            return response()->json(['message' => 'Prediction already completed.'], 200);
        }

        $validated = $request->validate([
            'status'                => 'required|in:completed,failed',
            'is_lum_a'              => 'nullable|boolean',
            'confidence_lum_a'      => 'nullable|numeric|between:0,1',
            'confidence_non_lum_a'  => 'nullable|numeric|between:0,1',
            'failure_reason'        => 'nullable|string',
            'xai'                   => 'nullable|array',
            'xai.heatmap_path'      => 'nullable|string',
            'xai.heatmap_status'    => 'nullable|in:pending,ready,failed',
            'xai.shap_plot_path'    => 'nullable|string',
            'xai.shap_status'       => 'nullable|in:pending,ready,failed',
            'xai.shap_values'       => 'nullable|array',
            'xai.top_features'      => 'nullable|array',
        ]);

        // Update prediction result
        $prediction->update([
            'status'               => $validated['status'],
            'is_lum_a'             => $validated['is_lum_a'] ?? null,
            'confidence_lum_a'     => $validated['confidence_lum_a'] ?? null,
            'confidence_non_lum_a' => $validated['confidence_non_lum_a'] ?? null,
            'failure_reason'       => $validated['failure_reason'] ?? null,
            'completed_at'         => now(),
        ]);

        // Persist XAI results if provided
        if (!empty($validated['xai'])) {
            XaiResult::updateOrCreate(
                ['prediction_id' => $prediction->id],
                [
                    'heatmap_path'   => $validated['xai']['heatmap_path'] ?? null,
                    'heatmap_status' => $validated['xai']['heatmap_status'] ?? 'pending',
                    'shap_plot_path' => $validated['xai']['shap_plot_path'] ?? null,
                    'shap_status'    => $validated['xai']['shap_status'] ?? 'pending',
                    'shap_values'    => $validated['xai']['shap_values'] ?? null,
                    'top_features'   => $validated['xai']['top_features'] ?? null,
                ]
            );
        }

        Log::info("Webhook: Prediction {$prediction->id} marked as {$validated['status']}.");

        return response()->json(['message' => 'Prediction result recorded successfully.']);
    }
}
