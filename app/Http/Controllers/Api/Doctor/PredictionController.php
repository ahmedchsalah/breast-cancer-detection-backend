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

class PredictionController extends Controller
{
    private function doctor()
    {
        return auth()->user();
    }

    /**
     * List predictions made by this doctor (via their examinations).
     */
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

    /**
     * Show a single prediction with XAI results.
     */
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

    /**
     * Dispatch an AI prediction for an examination.
     * This is the core action: it packages clinical data + WSI, calls FastAPI, and stores the result.
     */
    public function predict(Request $request): JsonResponse
    {
        $doctor = $this->doctor();

        $validated = $request->validate([
            'examination_id' => 'required|integer|exists:examinations,id',
            'wsi_upload_id'  => 'nullable|integer|exists:wsi_uploads,id',
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

        // Get the active AI model
        $aiModel = AiModel::where('is_active', true)->first();
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

        // Dispatch async job to FastAPI (fire and forget via queue job)
        // The webhook will update the prediction when FastAPI responds.
        dispatch(new \App\Jobs\DispatchPredictionJob($prediction));

        return response()->json([
            'message'       => 'Prediction dispatched. Results will be available shortly.',
            'prediction_id' => $prediction->id,
            'job_id'        => $prediction->job_id,
            'status'        => $prediction->status,
        ], 202);
    }

    /**
     * Retry a failed prediction.
     */
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

    /**
     * Poll the status of a prediction job (for frontend real-time updates).
     */
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
