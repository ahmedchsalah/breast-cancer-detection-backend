<?php

namespace App\Http\Controllers\Api\OrgManager;

use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Models\Patient;
use App\Models\Prediction;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InsightsController extends Controller
{
    private function orgId(): int
    {
        return auth()->user()->organization_id;
    }

    /**
     * KPI cards for the organization manager dashboard.
     */
    public function kpis(): JsonResponse
    {
        $orgId = $this->orgId();

        return response()->json([
            'total_members'          => User::where('organization_id', $orgId)->count(),
            'active_doctors'         => User::where('organization_id', $orgId)->where('is_active', true)->role('doctor')->count(),
            'pending_approvals'      => User::where('organization_id', $orgId)->where('is_active', false)->role('doctor')->count(),
            'total_patients'         => Patient::where('organization_id', $orgId)->count(),
            'total_examinations'     => Examination::where('organization_id', $orgId)->count(),
            'total_predictions'      => Prediction::where('organization_id', $orgId)->count(),
            'completed_predictions'  => Prediction::where('organization_id', $orgId)->where('status', Prediction::STATUS_COMPLETED)->count(),
            'total_reports'          => Report::where('organization_id', $orgId)->count(),
        ]);
    }

    /**
     * Patient registrations per month (last 12 months) – line chart.
     */
    public function patientGrowth(): JsonResponse
    {
        $data = Patient::where('organization_id', $this->orgId())
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                DB::raw('COUNT(*) as count')
            )
            ->where('created_at', '>=', now()->subYear())
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json($data);
    }

    /**
     * Predictions per month within this org – area chart.
     */
    public function predictionsOverTime(): JsonResponse
    {
        $data = Prediction::where('organization_id', $this->orgId())
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            )
            ->where('created_at', '>=', now()->subYear())
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json($data);
    }

    /**
     * Luminal-A vs Non-Luminal-A within this org – donut chart.
     */
    public function predictionResultsDistribution(): JsonResponse
    {
        $orgId = $this->orgId();
        $total    = Prediction::where('organization_id', $orgId)->where('status', Prediction::STATUS_COMPLETED)->count();
        $lumA     = Prediction::where('organization_id', $orgId)->where('status', Prediction::STATUS_COMPLETED)->where('is_lum_a', true)->count();
        $nonLumA  = Prediction::where('organization_id', $orgId)->where('status', Prediction::STATUS_COMPLETED)->where('is_lum_a', false)->count();

        return response()->json([
            'total'         => $total,
            'luminal_a'     => $lumA,
            'non_luminal_a' => $nonLumA,
        ]);
    }

    /**
     * Patient age distribution within this org – histogram.
     */
    public function patientAgeDistribution(): JsonResponse
    {
        $orgId = $this->orgId();
        $buckets = [
            '< 30'  => [0, 29],
            '30-39' => [30, 39],
            '40-49' => [40, 49],
            '50-59' => [50, 59],
            '60-69' => [60, 69],
            '70+'   => [70, 150],
        ];

        $result = [];
        foreach ($buckets as $label => [$min, $max]) {
            $result[] = [
                'range' => $label,
                'count' => Patient::where('organization_id', $orgId)->whereBetween('age', [$min, $max])->count(),
            ];
        }

        return response()->json($result);
    }

    /**
     * Receptor status distribution within this org – grouped bar chart.
     */
    public function receptorStatusDistribution(): JsonResponse
    {
        $orgId = $this->orgId();

        return response()->json([
            'er_positive'   => Patient::where('organization_id', $orgId)->where('er_status', true)->where('er_status_missing', false)->count(),
            'er_negative'   => Patient::where('organization_id', $orgId)->where('er_status', false)->where('er_status_missing', false)->count(),
            'er_missing'    => Patient::where('organization_id', $orgId)->where('er_status_missing', true)->count(),
            'pr_positive'   => Patient::where('organization_id', $orgId)->where('pr_status', true)->where('pr_status_missing', false)->count(),
            'pr_negative'   => Patient::where('organization_id', $orgId)->where('pr_status', false)->where('pr_status_missing', false)->count(),
            'pr_missing'    => Patient::where('organization_id', $orgId)->where('pr_status_missing', true)->count(),
            'her2_positive' => Patient::where('organization_id', $orgId)->where('her2_binary', true)->count(),
            'her2_negative' => Patient::where('organization_id', $orgId)->where('her2_binary', false)->count(),
        ]);
    }

    /**
     * Doctor activity leaderboard within this org (by predictions made) – bar chart.
     */
    public function doctorActivityLeaderboard(): JsonResponse
    {
        $orgId = $this->orgId();

        $data = Examination::where('organization_id', $orgId)
            ->select('doctor_id', DB::raw('COUNT(*) as examinations_count'))
            ->with('doctor:id,name,email')
            ->groupBy('doctor_id')
            ->orderByDesc('examinations_count')
            ->get();

        return response()->json($data);
    }

    /**
     * AI model performance – accuracy over rounds (read-only view for org manager).
     */
    public function modelPerformance(): JsonResponse
    {
        $data = \App\Models\FlRound::with('aiModel:id,name,version')
            ->orderBy('round_number')
            ->get(['id', 'ai_model_id', 'round_number', 'global_accuracy', 'status', 'started_at']);

        return response()->json($data);
    }
}
