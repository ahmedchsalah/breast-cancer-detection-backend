<?php

namespace App\Http\Controllers\Api\OrgManager;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    private function orgId(): int
    {
        return auth()->user()->organization_id;
    }

    /**
     * List all patients in this organization.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search'   => 'nullable|string|max:100',
            'her2'     => 'nullable|boolean',
            'er_status'=> 'nullable|boolean',
        ]);

        $query = Patient::where('organization_id', $this->orgId())
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

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    /**
     * Show a single patient with their examination history.
     */
    public function show(Patient $patient): JsonResponse
    {
        abort_if($patient->organization_id !== $this->orgId(), 403, 'This patient does not belong to your organization.');

        $patient->load(['examinations' => fn($q) => $q->with('prediction:id,examination_id,is_lum_a,status,completed_at')->orderByDesc('examined_at')])
            ->loadCount('examinations', 'predictions');

        return response()->json($patient);
    }
}
