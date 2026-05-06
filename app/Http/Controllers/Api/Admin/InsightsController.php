<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Examination;
use App\Models\FlRound;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Prediction;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InsightsController extends Controller
{
    /**
     * Platform-wide KPI cards for the admin dashboard header.
     */
    public function kpis(): JsonResponse
    {
        return response()->json([
            'total_organizations'  => Organization::count(),
            'active_organizations' => Organization::where('status', Organization::STATUS_ACTIVE)->count(),
            'pending_organizations'=> Organization::where('status', Organization::STATUS_PENDING)->count(),
            'total_users'          => User::count(),
            'total_patients'       => Patient::count(),
            'total_examinations'   => Examination::count(),
            'total_predictions'    => Prediction::count(),
            'completed_predictions'=> Prediction::where('status', Prediction::STATUS_COMPLETED)->count(),
            'active_ai_models'     => AiModel::where('is_active', true)->count(),
            'fl_rounds_completed'  => FlRound::where('status', 'completed')->count(),
        ]);
    }

    /**
     * User growth per month (last 12 months) – line chart.
     */
    public function userGrowth(): JsonResponse
    {
        $data = User::select(
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
     * Organization type distribution – pie/donut chart.
     */
    public function organizationDistribution(): JsonResponse
    {
        $data = Organization::select('type', DB::raw('COUNT(*) as count'))
            ->groupBy('type')
            ->get();

        return response()->json($data);
    }

    /**
     * Organization approval status breakdown – bar chart.
     */
    public function organizationStatusBreakdown(): JsonResponse
    {
        $data = Organization::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        return response()->json($data);
    }

    /**
     * Predictions per month (last 12 months) – area chart.
     */
    public function predictionsOverTime(): JsonResponse
    {
        $data = Prediction::select(
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
     * Luminal-A vs Non-Luminal-A classification results – pie chart.
     */
    public function predictionResultsDistribution(): JsonResponse
    {
        $total     = Prediction::where('status', Prediction::STATUS_COMPLETED)->count();
        $lumA      = Prediction::where('status', Prediction::STATUS_COMPLETED)->where('is_lum_a', true)->count();
        $nonLumA   = Prediction::where('status', Prediction::STATUS_COMPLETED)->where('is_lum_a', false)->count();

        return response()->json([
            'total'       => $total,
            'luminal_a'   => $lumA,
            'non_luminal_a' => $nonLumA,
        ]);
    }

    /**
     * Patient age distribution – histogram buckets.
     */
    public function patientAgeDistribution(): JsonResponse
    {
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
                'count' => Patient::whereBetween('age', [$min, $max])->count(),
            ];
        }

        return response()->json($result);
    }

    /**
     * Receptor status breakdown across all patients – grouped bar chart.
     */
    public function receptorStatusDistribution(): JsonResponse
    {
        return response()->json([
            'er_positive'  => Patient::where('er_status', true)->where('er_status_missing', false)->count(),
            'er_negative'  => Patient::where('er_status', false)->where('er_status_missing', false)->count(),
            'er_missing'   => Patient::where('er_status_missing', true)->count(),
            'pr_positive'  => Patient::where('pr_status', true)->where('pr_status_missing', false)->count(),
            'pr_negative'  => Patient::where('pr_status', false)->where('pr_status_missing', false)->count(),
            'pr_missing'   => Patient::where('pr_status_missing', true)->count(),
            'her2_positive'=> Patient::where('her2_binary', true)->count(),
            'her2_negative'=> Patient::where('her2_binary', false)->count(),
        ]);
    }

    /**
     * AI model performance over FL rounds – line chart.
     */
    public function modelPerformanceOverRounds(): JsonResponse
    {
        $data = FlRound::select('round_number', 'global_accuracy', 'status', 'ai_model_id', 'started_at')
            ->with('aiModel:id,name,version')
            ->orderBy('round_number')
            ->get();

        return response()->json($data);
    }

    /**
     * Top organizations by number of predictions made – horizontal bar chart.
     */
    public function topOrganizationsByActivity(): JsonResponse
    {
        $data = Prediction::select('organization_id', DB::raw('COUNT(*) as prediction_count'))
            ->with('organization:id,name,type')
            ->groupBy('organization_id')
            ->orderByDesc('prediction_count')
            ->limit(10)
            ->get();

        return response()->json($data);
    }
}
