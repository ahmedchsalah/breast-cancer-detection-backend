<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Examination;
use App\Models\Patient;
use App\Models\Prediction;
use App\Models\WsiUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "PredictionObject",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer"),
        new OA\Property(property: "examination_id", type: "integer"),
        new OA\Property(property: "patient_id", type: "integer"),
        new OA\Property(property: "ai_model_id", type: "integer"),
        new OA\Property(property: "wsi_upload_id", type: "integer", nullable: true),
        new OA\Property(property: "status", type: "string", enum: ["pending", "processing", "completed", "failed"]),
        new OA\Property(property: "is_lum_a", type: "boolean", nullable: true),
        new OA\Property(property: "confidence_lum_a", type: "number", format: "float", nullable: true),
        new OA\Property(property: "confidence_non_lum_a", type: "number", format: "float", nullable: true),
        new OA\Property(property: "failure_reason", type: "string", nullable: true),
        new OA\Property(property: "job_id", type: "string"),
        new OA\Property(property: "completed_at", type: "string", format: "date-time", nullable: true),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
    ]
)]
class PredictionController extends Controller
{
    private function doctor()
    {
        return auth()->user();
    }

    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/doctor/predictions",
        tags: ["Doctor — Predictions"],
        summary: "List predictions made by this doctor (via their examinations)",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "status", in: "query", required: false, schema: new OA\Schema(type: "string", enum: ["pending", "processing", "completed", "failed"])),
            new OA\Parameter(name: "examination_id", in: "query", required: false, schema: new OA\Schema(type: "integer")),
            new OA\Parameter(name: "patient_id", in: "query", required: false, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated list of predictions",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "data", type: "array", items: new OA\Items(ref: "#/components/schemas/PredictionObject")),
                        new OA\Property(property: "current_page", type: "integer"),
                        new OA\Property(property: "total", type: "integer"),
                    ]
                )
            )
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'         => 'nullable|in:pending,processing,completed,failed',
            'examination_id' => 'nullable|integer|exists:examinations,id',
            'patient_id'     => 'nullable|integer|exists:patients,id',
        ]);

        $query = Prediction::where('organization_id', $this->doctor()->organization_id)
            ->whereHas('examination', fn($q) => $q->where('doctor_id', $this->doctor()->id))
            ->with('patient:id,patient_identifier', 'examination:id,examined_at,status', 'aiModel:id,name,version');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('examination_id')) {
            $query->where('examination_id', $request->examination_id);
        }
        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(15));
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/doctor/predictions/{id}",
        tags: ["Doctor — Predictions"],
        summary: "Show a single prediction with XAI results",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Prediction details",
                content: new OA\JsonContent(ref: "#/components/schemas/PredictionObject")
            ),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(Prediction $prediction): JsonResponse
    {
        $this->ensureOwnership($prediction);

        $prediction->load([
            'patient',
            'examination:id,chief_complaint,clinical_notes,status,examined_at',
            'aiModel:id,name,version',
            'wsiUpload:id,original_name,file_path,status',
            'xaiResult',
        ]);

        return response()->json($prediction);
    }

    // ============================================================
    //  PREDICT (DISPATCH)
    // ============================================================

    #[OA\Post(
        path: "/doctor/predictions",
        tags: ["Doctor — Predictions"],
        summary: "Dispatch an AI prediction for an examination",
        description: "Packages clinical data + WSI, calls FastAPI, and stores the result as pending.",
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["examination_id"],
                properties: [
                    new OA\Property(property: "examination_id", type: "integer"),
                    new OA\Property(property: "wsi_upload_id", type: "integer", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 202, description: "Prediction dispatched"),
            new OA\Response(response: 403, description: "Not authorized / Organization mismatch"),
            new OA\Response(response: 422, description: "Examination not submitted / Prediction already exists"),
            new OA\Response(response: 503, description: "No active AI model available"),
        ]
    )]
    public function predict(Request $request): JsonResponse
    {
        $doctor = $this->doctor();

        $validated = $request->validate([
            'examination_id' => 'required|integer|exists:examinations,id',
            'wsi_upload_id'  => 'nullable|integer|exists:wsi_uploads,id',
            'ai_model_id'    => 'nullable|integer|exists:ai_models,id',
        ]);

        $examination = Examination::with('patient')->findOrFail($validated['examination_id']);

        // Authorization checks
        abort_if($examination->doctor_id !== $doctor->id, 403, 'This examination does not belong to you.');
        abort_if($examination->organization_id !== $doctor->organization_id, 403, 'Organization mismatch.');

        if ($examination->status !== Examination::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Examination must be in submitted status before running a prediction.'], 422);
        }

        if ($examination->prediction()->exists()) {
            return response()->json(['message' => 'A prediction has already been made for this examination.'], 422);
        }

        // Get the AI model (specific or first active)
        if ($request->filled('ai_model_id')) {
            $aiModel = AiModel::findOrFail($request->ai_model_id);
        } else {
            $aiModel = AiModel::where('is_active', true)->first();
        }

        if (!$aiModel) {
            return response()->json(['message' => 'No active AI model is available. Please contact your administrator.'], 503);
        }

        $patient = $examination->patient;

        // Build clinical input snapshot (what we send to FastAPI)
        $clinicalSnapshot = [
            'er_status'               => $patient->er_status,
            'pr_status'               => $patient->pr_status,
            'her2_binary'             => $patient->her2_binary,
            'age'                     => $patient->age,
            'stage_num'               => $patient->stage_num,
            'er_status_missing'       => $patient->er_status_missing,
            'pr_status_missing'       => $patient->pr_status_missing,
            'fraction_genome_altered' => $patient->fraction_genome_altered,
            'buffa_hypoxia_score'     => $patient->buffa_hypoxia_score,
            'ragnum_hypoxia_score'    => $patient->ragnum_hypoxia_score,
            'winter_hypoxia_score'    => $patient->winter_hypoxia_score,
        ];

        // Create the prediction record immediately (status: pending)
        $prediction = Prediction::create([
            'examination_id'          => $examination->id,
            'patient_id'              => $patient->id,
            'organization_id'         => $doctor->organization_id,
            'ai_model_id'             => $aiModel->id,
            'wsi_upload_id'           => $validated['wsi_upload_id'] ?? null,
            'clinical_input_snapshot' => $clinicalSnapshot,
            'status'                  => Prediction::STATUS_PENDING,
            'job_id'                  => Str::uuid(),
        ]);

        // Update examination status
        $examination->update(['status' => Examination::STATUS_PREDICTED]);

        // Determine dispatch mode:
        //   - R2 slide (r2_key set)  → queue (SVS processing takes 10+ min on CPU)
        //   - .pt features file      → dispatch_sync (HF responds in ~30s for pre-extracted features)
        //   - Clinical-only (no WSI) → dispatch_sync (~5s)
        $hasWsi = false;
        $hasR2Key = false;
        if ($validated['wsi_upload_id'] ?? null) {
            $wsiUpload = \App\Models\WsiUpload::find($validated['wsi_upload_id']);
            $hasR2Key  = !empty($wsiUpload?->r2_key);
            $hasPtFile = $wsiUpload?->features_path &&
                \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'))
                    ->exists($wsiUpload->features_path);
            $hasWsi = $hasR2Key || $hasPtFile;
        }

        if ($hasR2Key) {
            // R2 slide (SVS) — takes 10-20 min on HF CPU
            // Return 202 immediately, then process after response is sent
            // Uses register_shutdown_function to continue work after HTTP response
            $predictionId = $prediction->id;
            $response = response()->json([
                'message'       => 'Prediction dispatched. SVS processing in progress.',
                'prediction_id' => $prediction->id,
                'job_id'        => $prediction->job_id,
                'status'        => 'processing',
            ], 202);

            // Schedule the heavy work AFTER the response is sent to the client
            app()->terminating(function () use ($prediction) {
                try {
                    (new \App\Jobs\DispatchPredictionJob($prediction))->handle();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("[BReCAI] SVS prediction failed: {$e->getMessage()}");
                    $prediction->update([
                        'status'         => \App\Models\Prediction::STATUS_FAILED,
                        'failure_reason' => $e->getMessage(),
                        'completed_at'   => now(),
                    ]);
                }
            });

            return $response;
        }

        // .pt features file OR clinical-only — HF responds in seconds, run inline
        dispatch_sync(new \App\Jobs\DispatchPredictionJob($prediction));

        return response()->json([
            'message'       => 'Prediction dispatched. Results will be available shortly.',
            'prediction_id' => $prediction->id,
            'job_id'        => $prediction->job_id,
            'status'        => $prediction->fresh()->status,
            'is_lum_a'      => $prediction->fresh()->is_lum_a,
            'confidence_lum_a'     => $prediction->fresh()->confidence_lum_a,
            'confidence_non_lum_a' => $prediction->fresh()->confidence_non_lum_a,
        ], 202);
    }

    // ============================================================
    //  RETRY
    // ============================================================

    #[OA\Post(
        path: "/doctor/predictions/{id}/retry",
        tags: ["Doctor — Predictions"],
        summary: "Retry a failed prediction",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Prediction retried"),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 422, description: "Only failed predictions can be retried"),
        ]
    )]
    public function retry(Prediction $prediction): JsonResponse
    {
        $this->ensureOwnership($prediction);

        if ($prediction->status !== Prediction::STATUS_FAILED) {
            return response()->json(['message' => 'Only failed predictions can be retried.'], 422);
        }

        $prediction->update([
            'status'         => Prediction::STATUS_PENDING,
            'failure_reason' => null,
            'job_id'         => Str::uuid(),
        ]);

        dispatch(new \App\Jobs\DispatchPredictionJob($prediction));

        return response()->json([
            'message' => 'Prediction retried.',
            'status'  => Prediction::STATUS_PENDING,
        ]);
    }

    // ============================================================
    //  STATUS
    // ============================================================

    #[OA\Get(
        path: "/doctor/predictions/{id}/status",
        tags: ["Doctor — Predictions"],
        summary: "Poll the status of a prediction job",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Prediction status details",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "id", type: "integer"),
                        new OA\Property(property: "status", type: "string"),
                        new OA\Property(property: "is_lum_a", type: "boolean", nullable: true),
                        new OA\Property(property: "confidence_lum_a", type: "number", format: "float", nullable: true),
                        new OA\Property(property: "confidence_non_lum_a", type: "number", format: "float", nullable: true),
                        new OA\Property(property: "failure_reason", type: "string", nullable: true),
                        new OA\Property(property: "completed_at", type: "string", format: "date-time", nullable: true),
                    ]
                )
            ),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function status(Prediction $prediction): JsonResponse
    {
        $this->ensureOwnership($prediction);

        return response()->json([
            'id'             => $prediction->id,
            'status'         => $prediction->status,
            'is_lum_a'       => $prediction->is_lum_a,
            'confidence_lum_a'     => $prediction->confidence_lum_a,
            'confidence_non_lum_a' => $prediction->confidence_non_lum_a,
            'failure_reason'       => $prediction->failure_reason,
            'completed_at'         => $prediction->completed_at,
        ]);
    }

    private function ensureOwnership(Prediction $prediction): void
    {
        abort_if($prediction->organization_id !== $this->doctor()->organization_id, 403, 'You do not have access to this prediction.');
    }
}
