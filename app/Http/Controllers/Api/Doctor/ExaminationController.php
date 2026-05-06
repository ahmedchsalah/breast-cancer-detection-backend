<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExaminationController extends Controller
{
    private function doctor()
    {
        return auth()->user();
    }

    /**
     * List examinations created by this doctor.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'     => 'nullable|in:draft,submitted,predicted,concluded',
            'patient_id' => 'nullable|integer|exists:patients,id',
        ]);

        $query = Examination::where('doctor_id', $this->doctor()->id)
            ->with('patient:id,patient_identifier,age', 'prediction:id,examination_id,is_lum_a,status')
            ->orderByDesc('examined_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }

        return response()->json($query->paginate(15));
    }

    /**
     * Show a single examination with all related data.
     */
    public function show(Examination $examination): JsonResponse
    {
        $this->ensureOwnership($examination);

        $examination->load([
            'patient',
            'prediction.xaiResult',
            'report',
        ]);

        return response()->json($examination);
    }

    /**
     * Open a new examination for a patient.
     */
    public function store(Request $request): JsonResponse
    {
        $doctor = $this->doctor();

        $validated = $request->validate([
            'patient_id'       => 'required|integer|exists:patients,id',
            'chief_complaint'  => 'nullable|string|max:1000',
            'clinical_notes'   => 'nullable|string',
            'examined_at'      => 'nullable|date',
        ]);

        // Ensure patient belongs to same org
        $patient = Patient::findOrFail($validated['patient_id']);
        abort_if($patient->organization_id !== $doctor->organization_id, 403, 'Patient does not belong to your organization.');

        $examination = Examination::create([
            ...$validated,
            'doctor_id'       => $doctor->id,
            'organization_id' => $doctor->organization_id,
            'status'          => Examination::STATUS_DRAFT,
            'examined_at'     => $validated['examined_at'] ?? now(),
        ]);

        return response()->json($examination, 201);
    }

    /**
     * Update examination notes/complaint (only while in draft/submitted state).
     */
    public function update(Request $request, Examination $examination): JsonResponse
    {
        $this->ensureOwnership($examination);

        if ($examination->status === Examination::STATUS_CONCLUDED) {
            return response()->json(['message' => 'A concluded examination cannot be modified.'], 422);
        }

        $validated = $request->validate([
            'chief_complaint'    => 'nullable|string|max:1000',
            'clinical_notes'     => 'nullable|string',
            'doctor_conclusion'  => 'nullable|string',
            'examined_at'        => 'nullable|date',
        ]);

        $examination->update($validated);

        return response()->json($examination->fresh());
    }

    /**
     * Submit an examination (moves it from draft to submitted, ready for prediction).
     */
    public function submit(Examination $examination): JsonResponse
    {
        $this->ensureOwnership($examination);

        if ($examination->status !== Examination::STATUS_DRAFT) {
            return response()->json(['message' => 'Only draft examinations can be submitted.'], 422);
        }

        $examination->update(['status' => Examination::STATUS_SUBMITTED]);

        return response()->json(['message' => 'Examination submitted successfully.', 'examination' => $examination]);
    }

    /**
     * Conclude an examination (doctor has reviewed the AI result and written their conclusion).
     */
    public function conclude(Request $request, Examination $examination): JsonResponse
    {
        $this->ensureOwnership($examination);

        if ($examination->status !== Examination::STATUS_PREDICTED) {
            return response()->json(['message' => 'Examination can only be concluded after a prediction has been made.'], 422);
        }

        $validated = $request->validate([
            'doctor_conclusion' => 'required|string',
        ]);

        $examination->update([
            'doctor_conclusion' => $validated['doctor_conclusion'],
            'status'            => Examination::STATUS_CONCLUDED,
        ]);

        return response()->json(['message' => 'Examination concluded.', 'examination' => $examination->fresh()]);
    }

    /**
     * Delete a draft examination.
     */
    public function destroy(Examination $examination): JsonResponse
    {
        $this->ensureOwnership($examination);

        if ($examination->status !== Examination::STATUS_DRAFT) {
            return response()->json(['message' => 'Only draft examinations can be deleted.'], 422);
        }

        $examination->delete();

        return response()->json(['message' => 'Examination deleted.']);
    }

    private function ensureOwnership(Examination $examination): void
    {
        abort_if($examination->doctor_id !== $this->doctor()->id, 403, 'You do not have access to this examination.');
    }
}
