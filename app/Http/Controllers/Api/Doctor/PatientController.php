<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    private function doctor()
    {
        return auth()->user();
    }

    /**
     * List patients belonging to this doctor's organization.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search'    => 'nullable|string|max:100',
            'her2'      => 'nullable|boolean',
            'er_status' => 'nullable|boolean',
            'stage_num' => 'nullable|integer|between:1,4',
        ]);

        $query = Patient::where('organization_id', $this->doctor()->organization_id)
            ->withCount('examinations', 'predictions');

        if ($request->filled('search')) {
            $query->where('patient_identifier', 'like', '%' . $request->search . '%');
        }
        if ($request->filled('her2')) {
            $query->where('her2_binary', filter_var($request->her2, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('er_status')) {
            $query->where('er_status', filter_var($request->er_status, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('stage_num')) {
            $query->where('stage_num', $request->stage_num);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    /**
     * Show a patient with their full examination & prediction history.
     */
    public function show(Patient $patient): JsonResponse
    {
        $this->ensureSameOrg($patient);

        $patient->load([
            'examinations' => fn($q) => $q->with([
                'prediction:id,examination_id,is_lum_a,confidence_lum_a,confidence_non_lum_a,status,completed_at',
                'report:id,examination_id,status,file_path',
            ])->orderByDesc('examined_at'),
        ])->loadCount('examinations', 'predictions');

        return response()->json($patient);
    }

    /**
     * Register a new patient.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_identifier'      => 'required|string|max:50|unique:patients,patient_identifier',
            'er_status'               => 'required|boolean',
            'pr_status'               => 'required|boolean',
            'her2_binary'             => 'required|boolean',
            'age'                     => 'required|integer|min:0|max:120',
            'stage_num'               => 'required|integer|between:1,4',
            'er_status_missing'       => 'nullable|boolean',
            'pr_status_missing'       => 'nullable|boolean',
            'fraction_genome_altered' => 'nullable|numeric|between:0,1',
            'buffa_hypoxia_score'     => 'nullable|numeric',
            'ragnum_hypoxia_score'    => 'nullable|numeric',
            'winter_hypoxia_score'    => 'nullable|numeric',
        ]);

        $validated['organization_id'] = $this->doctor()->organization_id;

        $patient = Patient::create($validated);

        return response()->json($patient, 201);
    }

    /**
     * Update clinical data for a patient.
     */
    public function update(Request $request, Patient $patient): JsonResponse
    {
        $this->ensureSameOrg($patient);

        $validated = $request->validate([
            'er_status'               => 'sometimes|boolean',
            'pr_status'               => 'sometimes|boolean',
            'her2_binary'             => 'sometimes|boolean',
            'age'                     => 'sometimes|integer|min:0|max:120',
            'stage_num'               => 'sometimes|integer|between:1,4',
            'er_status_missing'       => 'nullable|boolean',
            'pr_status_missing'       => 'nullable|boolean',
            'fraction_genome_altered' => 'nullable|numeric|between:0,1',
            'buffa_hypoxia_score'     => 'nullable|numeric',
            'ragnum_hypoxia_score'    => 'nullable|numeric',
            'winter_hypoxia_score'    => 'nullable|numeric',
        ]);

        $patient->update($validated);

        return response()->json($patient->fresh());
    }

    /**
     * Delete a patient record. Only if they have no predictions.
     */
    public function destroy(Patient $patient): JsonResponse
    {
        $this->ensureSameOrg($patient);

        if ($patient->predictions()->exists()) {
            return response()->json(['message' => 'Cannot delete a patient that has prediction records.'], 422);
        }

        $patient->delete();

        return response()->json(['message' => 'Patient deleted successfully.']);
    }

    private function ensureSameOrg(Patient $patient): void
    {
        abort_if($patient->organization_id !== $this->doctor()->organization_id, 403, 'Patient does not belong to your organization.');
    }
}
