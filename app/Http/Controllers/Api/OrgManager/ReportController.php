<?php

namespace App\Http\Controllers\Api\OrgManager;

use App\Http\Controllers\Controller;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    private function orgId(): int
    {
        return auth()->user()->organization_id;
    }

    /**
     * List all reports generated within this organization.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'    => 'nullable|in:draft,final',
            'doctor_id' => 'nullable|integer|exists:users,id',
        ]);

        $query = Report::where('organization_id', $this->orgId())
            ->with('patient:id,patient_identifier', 'doctor:id,name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    /**
     * Show a single report with prediction details.
     */
    public function show(Report $report): JsonResponse
    {
        abort_if($report->organization_id !== $this->orgId(), 403, 'This report does not belong to your organization.');

        $report->load([
            'patient:id,patient_identifier,age,er_status,pr_status,her2_binary',
            'doctor:id,name,email',
            'examination:id,chief_complaint,status,examined_at',
            'prediction:id,is_lum_a,confidence_lum_a,confidence_non_lum_a,status',
        ]);

        return response()->json($report);
    }
}
