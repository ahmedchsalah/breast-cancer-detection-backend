<?php

namespace App\Http\Controllers\Api\OrgManager;

use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Models\Patient;
use App\Models\Prediction;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Returns the full summary card data for the org manager's main dashboard page.
     * This is a single combined endpoint for the dashboard's "above the fold" view.
     */
    public function summary(): JsonResponse
    {
        $orgId = auth()->user()->organization_id;

        $pendingDoctors = User::where('organization_id', $orgId)
            ->where('is_active', false)
            ->role('doctor')
            ->select('id', 'name', 'email', 'created_at')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $recentPatients = Patient::where('organization_id', $orgId)
            ->select('id', 'patient_identifier', 'age', 'created_at')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $recentPredictions = Prediction::where('organization_id', $orgId)
            ->with('patient:id,patient_identifier', 'doctor:id,name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'examination_id', 'patient_id', 'is_lum_a', 'status', 'created_at']);

        return response()->json([
            'kpis' => [
                'total_members'         => User::where('organization_id', $orgId)->count(),
                'pending_approvals'     => $pendingDoctors->count(),
                'total_patients'        => Patient::where('organization_id', $orgId)->count(),
                'predictions_this_month'=> Prediction::where('organization_id', $orgId)
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->count(),
            ],
            'pending_doctors'    => $pendingDoctors,
            'recent_patients'    => $recentPatients,
            'recent_predictions' => $recentPredictions,
        ]);
    }
}
