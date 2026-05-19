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
use OpenApi\Attributes as OA;

class InsightsController extends Controller
{
    private function orgId(): int
    {
        return auth()->user()->organization_id;
    }

    // ============================================================
    //  KPIs
    // ============================================================

    #[OA\Get(
        path: "/org-manager/insights/kpis",
        tags: ["OrgManager — Insights"],
        summary: "KPI cards for the organization manager dashboard",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "OrgManager KPIs",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "total_members", type: "integer"),
                        new OA\Property(property: "active_doctors", type: "integer"),
                        new OA\Property(property: "pending_approvals", type: "integer"),
                        new OA\Property(property: "total_patients", type: "integer"),
                        new OA\Property(property: "total_examinations", type: "integer"),
                        new OA\Property(property: "total_predictions", type: "integer"),
                        new OA\Property(property: "completed_predictions", type: "integer"),
                        new OA\Property(property: "total_reports", type: "integer"),
                    ]
                )
            )
        ]
    )]
    public function kpis(): JsonResponse
    {
        $orgId = $this->orgId();

        return response()->json([
            'total_members'          => User::where('organization_id', $orgId)->role('doctor')->count(),
            'active_doctors'         => User::where('organization_id', $orgId)->where('is_active', true)->role('doctor')->count(),
            'pending_approvals'      => User::where('organization_id', $orgId)->where('is_active', false)->role('doctor')->count(),
            'total_patients'         => Patient::where('organization_id', $orgId)->count(),
            'total_examinations'     => Examination::where('organization_id', $orgId)->count(),
            'total_predictions'      => Prediction::where('organization_id', $orgId)->count(),
            'completed_predictions'  => Prediction::where('organization_id', $orgId)->where('status', Prediction::STATUS_COMPLETED)->count(),
            'total_reports'          => Report::where('organization_id', $orgId)->count(),
        ]);
    }

    // ============================================================
    //  Patient Growth
    // ============================================================

    #[OA\Get(
        path: "/org-manager/insights/patient-growth",
        tags: ["OrgManager — Insights"],
        summary: "Patient registrations per month (last 12 months)",
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
                            new OA\Property(property: "count", type: "integer", example: 45),
                        ]
                    )
                )
            )
        ]
    )]
    public function patientGrowth(): JsonResponse
    {
        $data = Patient::where('organization_id', $this->orgId())
            ->select(
                DB::raw("TO_CHAR(created_at, 'YYYY-MM') as month"),
                DB::raw('COUNT(*) as count')
            )
            ->where('created_at', '>=', now()->subYear())
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json($data);
    }

    // ============================================================
    //  Predictions Over Time
    // ============================================================

    #[OA\Get(
        path: "/org-manager/insights/predictions-over-time",
        tags: ["OrgManager — Insights"],
        summary: "Predictions per month within this org",
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
                            new OA\Property(property: "total", type: "integer"),
                            new OA\Property(property: "completed", type: "integer"),
                            new OA\Property(property: "failed", type: "integer"),
                        ]
                    )
                )
            )
        ]
    )]
    public function predictionsOverTime(): JsonResponse
    {
        $data = Prediction::where('organization_id', $this->orgId())
            ->select(
                DB::raw("TO_CHAR(created_at, 'YYYY-MM') as month"),
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
        path: "/org-manager/insights/prediction-results",
        tags: ["OrgManager — Insights"],
        summary: "Luminal-A vs Non-Luminal-A within this org",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Donut chart data",
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

    // ============================================================
    //  Patient Age Distribution
    // ============================================================

    #[OA\Get(
        path: "/org-manager/insights/patient-age-distribution",
        tags: ["OrgManager — Insights"],
        summary: "Patient age distribution within this org",
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
                            new OA\Property(property: "count", type: "integer"),
                        ]
                    )
                )
            )
        ]
    )]
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

    // ============================================================
    //  Receptor Status Distribution
    // ============================================================

    #[OA\Get(
        path: "/org-manager/insights/receptor-status",
        tags: ["OrgManager — Insights"],
        summary: "Receptor status distribution within this org",
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

    // ============================================================
    //  Doctor Activity Leaderboard
    // ============================================================

    #[OA\Get(
        path: "/org-manager/insights/doctor-leaderboard",
        tags: ["OrgManager — Insights"],
        summary: "Doctor activity leaderboard within this org",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Bar chart data",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: "doctor_id", type: "integer"),
                            new OA\Property(property: "examinations_count", type: "integer"),
                            new OA\Property(property: "doctor", type: "object", properties: [
                                new OA\Property(property: "id", type: "integer"),
                                new OA\Property(property: "name", type: "string"),
                                new OA\Property(property: "email", type: "string"),
                            ]),
                        ]
                    )
                )
            )
        ]
    )]
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

    // ============================================================
    //  Model Performance
    // ============================================================

    #[OA\Get(
        path: "/org-manager/insights/model-performance",
        tags: ["OrgManager — Insights"],
        summary: "AI model performance – accuracy over rounds",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Read-only FL round view",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: "id", type: "integer"),
                            new OA\Property(property: "ai_model_id", type: "integer"),
                            new OA\Property(property: "round_number", type: "integer"),
                            new OA\Property(property: "global_accuracy", type: "number", format: "float"),
                            new OA\Property(property: "status", type: "string"),
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
    public function modelPerformance(): JsonResponse
    {
        $data = \App\Models\FlRound::with('aiModel:id,name,version')
            ->orderBy('round_number')
            ->get(['id', 'ai_model_id', 'round_number', 'global_accuracy', 'status', 'started_at']);

        return response()->json($data);
    }
}
