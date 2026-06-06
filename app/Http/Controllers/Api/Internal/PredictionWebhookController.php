<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\Prediction;
use App\Models\XaiResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

/**
 * Called by the FastAPI microservice after a prediction job is completed.
 * This route should be protected by a shared secret key (not Sanctum).
 */
class PredictionWebhookController extends Controller
{
    #[OA\Post(
        path: "/internal/predictions/{job_id}/result",
        tags: ["Webhooks"],
        summary: "Receive prediction results from FastAPI",
        description: "Internal webhook for the FastAPI service to push prediction and XAI results back. Protected by a shared secret.",
        parameters: [
            new OA\Parameter(name: "job_id", in: "path", required: true, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "X-Internal-Secret", in: "header", required: true, schema: new OA\Schema(type: "string")),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["status"],
                properties: [
                    new OA\Property(property: "status", type: "string", enum: ["completed", "failed"]),
                    new OA\Property(property: "is_lum_a", type: "boolean", nullable: true),
                    new OA\Property(property: "confidence_lum_a", type: "number", format: "float", nullable: true),
                    new OA\Property(property: "confidence_non_lum_a", type: "number", format: "float", nullable: true),
                    new OA\Property(property: "failure_reason", type: "string", nullable: true),
                    new OA\Property(property: "xai", type: "object", properties: [
                        new OA\Property(property: "heatmap_path", type: "string", nullable: true),
                        new OA\Property(property: "heatmap_status", type: "string", enum: ["pending", "ready", "failed"]),
                        new OA\Property(property: "shap_plot_path", type: "string", nullable: true),
                        new OA\Property(property: "shap_status", type: "string", enum: ["pending", "ready", "failed"]),
                        new OA\Property(property: "shap_values", type: "object", nullable: true),
                        new OA\Property(property: "top_features", type: "array", items: new OA\Items(type: "string"), nullable: true),
                    ], nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Result recorded successfully"),
            new OA\Response(response: 401, description: "Unauthorized (invalid secret)"),
            new OA\Response(response: 404, description: "Prediction not found"),
        ]
    )]
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

        // Persist XAI results — handle both old format (xai.* fields) and new format (patch_attention, gate_*, xai_r2_key)
        $data = $request->all();
        $topFeatures = [];
        $heatmapPath = null;

        // New format from /predict/a6 response
        if (!empty($data['patch_attention'])) {
            $topFeatures['top_patches'] = $data['patch_attention'];
        }
        if (isset($data['gate_img'], $data['gate_clin'])) {
            $topFeatures['fusion_gate'] = [
                'image_weight'    => round($data['gate_img'], 4),
                'clinical_weight' => round($data['gate_clin'], 4),
            ];
        }
        // R2 heatmap key (uploaded by HF for SVS predictions)
        if (!empty($data['xai_r2_key'])) {
            $heatmapPath = $data['xai_r2_key'];
        }

        // R2 segmentation overlay key
        $segmentationPath = $data['segmentation_r2_key'] ?? null;

        // R2 top-patches grid key
        $patchesPath = $data['patches_r2_key'] ?? null;

        // Old format (xai.* fields)
        if (!empty($validated['xai'])) {
            XaiResult::updateOrCreate(
                ['prediction_id' => $prediction->id],
                [
                    'heatmap_path'   => $validated['xai']['heatmap_path'] ?? null,
                    'heatmap_status' => $validated['xai']['heatmap_status'] ?? 'pending',
                    'shap_plot_path' => $validated['xai']['shap_plot_path'] ?? null,
                    'shap_status'    => $validated['xai']['shap_status'] ?? 'pending',
                    'shap_values'    => $validated['xai']['shap_values'] ?? null,
                    'top_features'   => !empty($topFeatures) ? $topFeatures : ($validated['xai']['top_features'] ?? null),
                ]
            );
        } elseif (!empty($topFeatures) || $heatmapPath) {
            // New format only (no xai wrapper)
            XaiResult::updateOrCreate(
                ['prediction_id' => $prediction->id],
                [
                    'top_features'      => $topFeatures,
                    'shap_status'       => 'completed',
                    'heatmap_path'      => $heatmapPath,
                    'segmentation_path' => $segmentationPath,
                    'patches_path'      => $patchesPath,
                    'heatmap_status'    => $heatmapPath ? 'completed' : (!empty($data['patch_attention']) ? 'completed' : 'pending'),
                ]
            );
        }

        // Auto-conclude examination + auto-create & finalize report + send email
        if ($validated['status'] === 'completed') {
            try {
                $examination = $prediction->examination;
                if ($examination && $examination->status === \App\Models\Examination::STATUS_PREDICTED) {
                    $label = ($validated['is_lum_a'] ?? false) ? 'Luminal A' : 'Non-Luminal A';
                    $conf = round(($validated['confidence_lum_a'] ?? 0) * 100, 1);
                    $examination->update([
                        'status'            => \App\Models\Examination::STATUS_CONCLUDED,
                        'doctor_conclusion' => "AI Classification: {$label} ({$conf}% confidence). Auto-concluded.",
                    ]);

                    // Auto-create report (or fetch existing)
                    $report = \App\Models\Report::where('prediction_id', $prediction->id)->first();
                    if (!$report) {
                        $report = \App\Models\Report::create([
                            'examination_id'  => $examination->id,
                            'prediction_id'   => $prediction->id,
                            'patient_id'      => $prediction->patient_id,
                            'doctor_id'       => $examination->doctor_id,
                            'organization_id' => $prediction->organization_id,
                            'status'          => 'draft',
                        ]);
                    }

                    // Auto-finalize the report and email it to the doctor
                    if ($report->status !== 'final') {
                        $report->update(['status' => 'final']);
                    }

                    $this->sendReportEmail($report);
                }
            } catch (\Throwable $e) {
                Log::warning("Webhook: Auto-report failed for prediction {$prediction->id}: {$e->getMessage()}");
            }
        }

        Log::info("Webhook: Prediction {$prediction->id} marked as {$validated['status']}.");

        return response()->json(['message' => 'Prediction result recorded successfully.']);
    }

    private function sendReportEmail(\App\Models\Report $report): void
    {
        try {
            $doctor = \App\Models\User::find($report->doctor_id);
            if (!$doctor || !$doctor->email) {
                Log::warning("Webhook: Cannot send report email — doctor not found for report #{$report->id}");
                return;
            }
            $doctor->load('organization');

            $report->load(['patient', 'prediction.aiModel', 'examination', 'prediction.xaiResult']);

            $reportController = new \App\Http\Controllers\Api\Doctor\ReportController();
            $htmlContent = $reportController->generateReportHtml($report, $doctor);

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($htmlContent)->setPaper('a4', 'portrait');
            $pdfBytes = $pdf->output();
            $b64Content = base64_encode($pdfBytes);
            $filename = "report-{$report->patient?->patient_identifier}-{$report->id}.pdf";

            \Illuminate\Support\Facades\Mail::to($doctor->email)
                ->send(new \App\Mail\ReportGeneratedMail($report, $doctor, $b64Content, $filename));

            Log::info("Webhook: Report email sent to {$doctor->email} for report #{$report->id}");
        } catch (\Throwable $e) {
            Log::warning("Webhook: Report email failed for report #{$report->id}: {$e->getMessage()}");
        }
    }
}
