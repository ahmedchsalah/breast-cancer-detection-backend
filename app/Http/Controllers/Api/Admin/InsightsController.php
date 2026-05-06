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
use OpenApi\Attributes as OA;

class InsightsController extends Controller
{
    // ============================================================
    //  KPIs
    // ============================================================

    #[OA\Get(
        path: "/admin/insights/kpis",
        tags: ["Admin — Insights"],
        summary: "Platform-wide KPI cards for the admin dashboard header",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Key Performance Indicators",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "total_organizations", type: "integer"),
                        new OA\Property(property: "active_organizations", type: "integer"),
                        new OA\Property(property: "pending_organizations", type: "integer"),
                        new OA\Property(property: "total_users", type: "integer"),
                        new OA\Property(property: "total_patients", type: "integer"),
                        new OA\Property(property: "total_examinations", type: "integer"),
                        new OA\Property(property: "total_predictions", type: "integer"),
                        new OA\Property(property: "completed_predictions", type: "integer"),
                        new OA\Property(property: "active_ai_models", type: "integer"),
                        new OA\Property(property: "fl_rounds_completed", type: "integer"),
                    ]
                )
            )
        ]
    )]
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

    // ============================================================
    //  User Growth
    // ============================================================

    #[OA\Get(
        path: "/admin/insights/user-growth",
        tags: ["Admin — Insights"],
        summary: "User growth per month (last 12 months)",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Line chart data",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: "month", type: "string", example: "2025-06"),
                            new OA\Property(property: "count", type: "integer", example: 42),
                        ]
                    )
                )
            )
        ]
    )]
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

    // ============================================================
    //  Organization Distribution
    // ============================================================

    #[OA\Get(
        path: "/admin/insights/organization-distribution",
        tags: ["Admin — Insights"],
        summary: "Organization type distribution",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Pie/Donut chart data",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: "type", type: "string", example: "hospital"),
                            new OA\Property(property: "count", type: "integer", example: 15),
                        ]
                    )
                )
            )
        ]
    )]
    public function organizationDistribution(): JsonResponse
    {
        $data = Organization::select('type', DB::raw('COUNT(*) as count'))
            ->groupBy('type')
            ->get();

        return response()->json($data);
    }

    // ============================================================
    //  Organization Status Breakdown
    // ============================================================

    #[OA\Get(
        path: "/admin/insights/organization-status-breakdown",
        tags: ["Admin — Insights"],
        summary: "Organization approval status breakdown",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Bar chart data",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: "status", type: "string", example: "active"),
                            new OA\Property(property: "count", type: "integer", example: 10),
                        ]
                    )
                )
            )
        ]
    )]
    public function organizationStatusBreakdown(): JsonResponse
    {
        $data = Organization::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        return response()->json($data);
    }

    // ============================================================
    //  Predictions Over Time
    // ============================================================

    #[OA\Get(
        path: "/admin/insights/predictions-over-time",
        tags: ["Admin — Insights"],
        summary: "Predictions per month (last 12 months)",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Area chart data",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: "month", type: "string", example: "2025-06"),
                            new OA\Property(property: "total", type: "integer", example: 100),
                            new OA\Property(property: "completed", type: "integer", example: 95),
                            new OA\Property(property: "failed", type: "integer", example: 5),
                        ]
                    )
                )
            )
        ]
    )]
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

    // ============================================================
    //  Prediction Results Distribution
    // ============================================================

    #[OA\Get(
        path: "/admin/insights/prediction-results",
        tags: ["Admin — Insights"],
        summary: "Luminal-A vs Non-Luminal-A classification results",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Pie chart data",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "total", type: "integer"),
                        new OA\Property(property: "luminal_a", type: "integer"),
                        new OA\Property(property: "non_luminal_a", type: "integer"),
                    ]
                )
            )
        ]
    )]
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

    // ============================================================
    //  Patient Age Distribution
    // ============================================================

    #[OA\Get(
        path: "/admin/insights/patient-age-distribution",
        tags: ["Admin — Insights"],
        summary: "Patient age distribution – histogram buckets",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Histogram data",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: "range", type: "string", example: "40-49"),
                            new OA\Property(property: "count", type: "integer", example: 35),
                        ]
                    )
                )
            )
        ]
    )]
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

    // ============================================================
    //  Receptor Status Distribution
    // ============================================================

    #[OA\Get(
        path: "/admin/insights/receptor-status",
        tags: ["Admin — Insights"],
        summary: "Receptor status breakdown across all patients",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Grouped bar chart data",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "er_positive", type: "integer"),
                        new OA\Property(property: "er_negative", type: "integer"),
                        new OA\Property(property: "er_missing", type: "integer"),
                        new OA\Property(property: "pr_positive", type: "integer"),
                        new OA\Property(property: "pr_negative", type: "integer"),
                        new OA\Property(property: "pr_missing", type: "integer"),
                        new OA\Property(property: "her2_positive", type: "integer"),
                        new OA\Property(property: "her2_negative", type: "integer"),
                    ]
                )
            )
        ]
    )]
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

    // ============================================================
    //  Model Performance Over Rounds
    // ============================================================

    #[OA\Get(
        path: "/admin/insights/model-performance",
        tags: ["Admin — Insights"],
        summary: "AI model performance over FL rounds",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Line chart data",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: "round_number", type: "integer"),
                            new OA\Property(property: "global_accuracy", type: "number", format: "float"),
                            new OA\Property(property: "status", type: "string"),
                            new OA\Property(property: "ai_model_id", type: "integer"),
                            new OA\Property(property: "started_at", type: "string", format: "date-time"),
                            new OA\Property(property: "ai_model", type: "object", properties: [
                                new OA\Property(property: "id", type: "integer"),
                                new OA\Property(property: "name", type: "string"),
                                new OA\Property(property: "version", type: "string"),
                            ]),
                        ]
                    )
                )
            )
        ]
    )]
    public function modelPerformanceOverRounds(): JsonResponse
    {
        $data = FlRound::select('round_number', 'global_accuracy', 'status', 'ai_model_id', 'started_at')
            ->with('aiModel:id,name,version')
            ->orderBy('round_number')
            ->get();

        return response()->json($data);
    }

    // ============================================================
    //  Top Organizations By Activity
    // ============================================================

    #[OA\Get(
        path: "/admin/insights/top-organizations",
        tags: ["Admin — Insights"],
        summary: "Top organizations by number of predictions made",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Horizontal bar chart data",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: "organization_id", type: "integer"),
                            new OA\Property(property: "prediction_count", type: "integer"),
                            new OA\Property(property: "organization", type: "object", properties: [
                                new OA\Property(property: "id", type: "integer"),
                                new OA\Property(property: "name", type: "string"),
                                new OA\Property(property: "type", type: "string"),
                            ]),
                        ]
                    )
                )
            )
        ]
    )]
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
