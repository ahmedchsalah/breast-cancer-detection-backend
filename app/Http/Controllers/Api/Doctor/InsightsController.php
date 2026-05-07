<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Models\Patient;
use App\Models\Prediction;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class InsightsController extends Controller
{
    private function doctor()
    {
        return auth()->user();
    }

    // ============================================================
    //  KPIs
    // ============================================================

    #[OA\Get(
        path: "/doctor/insights/kpis",
        tags: ["Doctor — Insights"],
        summary: "KPI cards for the doctor's personal dashboard",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Doctor KPIs",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "my_patients", type: "integer"),
                        new OA\Property(property: "my_examinations", type: "integer"),
                        new OA\Property(property: "pending_examinations", type: "integer"),
                        new OA\Property(property: "my_predictions", type: "integer"),
                        new OA\Property(property: "completed_predictions", type: "integer"),
                        new OA\Property(property: "failed_predictions", type: "integer"),
                        new OA\Property(property: "my_reports", type: "integer"),
                        new OA\Property(property: "finalized_reports", type: "integer"),
                    ]
                )
            )
        ]
    )]
    public function kpis(): JsonResponse
    {
        $doctorId = $this->doctor()->id;
        $orgId    = $this->doctor()->organization_id;

        return response()->json([
            'my_patients'            => Patient::where('organization_id', $orgId)->count(),
            'my_examinations'        => Examination::where('doctor_id', $doctorId)->count(),
            'pending_examinations'   => Examination::where('doctor_id', $doctorId)
                                            ->whereIn('status', [Examination::STATUS_DRAFT, Examination::STATUS_SUBMITTED])
                                            ->count(),
            'my_predictions'         => Prediction::whereHas('examination', fn($q) => $q->where('doctor_id', $doctorId))->count(),
            'completed_predictions'  => Prediction::whereHas('examination', fn($q) => $q->where('doctor_id', $doctorId))
                                            ->where('status', Prediction::STATUS_COMPLETED)
                                            ->count(),
            'failed_predictions'     => Prediction::whereHas('examination', fn($q) => $q->where('doctor_id', $doctorId))
                                            ->where('status', Prediction::STATUS_FAILED)
                                            ->count(),
            'my_reports'             => Report::where('doctor_id', $doctorId)->count(),
            'finalized_reports'      => Report::where('doctor_id', $doctorId)->where('status', Report::STATUS_FINAL)->count(),
        ]);
    }

    // ============================================================
    //  Examinations Over Time
    // ============================================================

    #[OA\Get(
        path: "/doctor/insights/examinations-over-time",
        tags: ["Doctor — Insights"],
        summary: "My examinations per month (last 12 months)",
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
                            new OA\Property(property: "count", type: "integer", example: 12),
                        ]
                    )
                )
            )
        ]
    )]
    public function examinationsOverTime(): JsonResponse
    {
        $data = Examination::where('doctor_id', $this->doctor()->id)
            ->select(
                DB::raw("TO_CHAR(examined_at, 'YYYY-MM') as month"),
                DB::raw('COUNT(*) as count')
            )
            ->where('examined_at', '>=', now()->subYear())
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json($data);
    }

    // ============================================================
    //  Prediction Results Distribution
    // ============================================================

    #[OA\Get(
        path: "/doctor/insights/prediction-results",
        tags: ["Doctor — Insights"],
        summary: "My prediction results distribution (Lum-A vs Non-Lum-A)",
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
    public function myPredictionResultsDistribution(): JsonResponse
    {
        $doctorId = $this->doctor()->id;

        $base   = Prediction::whereHas('examination', fn($q) => $q->where('doctor_id', $doctorId))
                    ->where('status', Prediction::STATUS_COMPLETED);

        $lumA   = (clone $base)->where('is_lum_a', true)->count();
        $nonLumA = (clone $base)->where('is_lum_a', false)->count();

        return response()->json([
            'total'         => $lumA + $nonLumA,
            'luminal_a'     => $lumA,
            'non_luminal_a' => $nonLumA,
        ]);
    }

    // ============================================================
    //  Average Confidence
    // ============================================================

    #[OA\Get(
        path: "/doctor/insights/average-confidence",
        tags: ["Doctor — Insights"],
        summary: "Average confidence scores of my predictions",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Average confidence metrics",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "avg_confidence_lum_a", type: "number", format: "float"),
                        new OA\Property(property: "avg_confidence_non_lum_a", type: "number", format: "float"),
                    ]
                )
            )
        ]
    )]
    public function averageConfidence(): JsonResponse
    {
        $doctorId = $this->doctor()->id;

        $result = Prediction::whereHas('examination', fn($q) => $q->where('doctor_id', $doctorId))
            ->where('status', Prediction::STATUS_COMPLETED)
            ->select(
                DB::raw('AVG(confidence_lum_a) as avg_confidence_lum_a'),
                DB::raw('AVG(confidence_non_lum_a) as avg_confidence_non_lum_a')
            )
            ->first();

        return response()->json([
            'avg_confidence_lum_a'     => round($result->avg_confidence_lum_a ?? 0, 4),
            'avg_confidence_non_lum_a' => round($result->avg_confidence_non_lum_a ?? 0, 4),
        ]);
    }

    // ============================================================
    //  Patient Age Distribution
    // ============================================================

    #[OA\Get(
        path: "/doctor/insights/patient-age-distribution",
        tags: ["Doctor — Insights"],
        summary: "Patient age distribution for my patients",
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
                            new OA\Property(property: "count", type: "integer", example: 10),
                        ]
                    )
                )
            )
        ]
    )]
    public function patientAgeDistribution(): JsonResponse
    {
        $orgId = $this->doctor()->organization_id;
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
    //  Recent Activity
    // ============================================================

    #[OA\Get(
        path: "/doctor/insights/recent-activity",
        tags: ["Doctor — Insights"],
        summary: "Recent activity feed – last 10 examinations",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "List of recent examinations",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: "id", type: "integer"),
                            new OA\Property(property: "status", type: "string"),
                            new OA\Property(property: "examined_at", type: "string", format: "date-time"),
                            new OA\Property(property: "patient", type: "object", properties: [
                                new OA\Property(property: "id", type: "integer"),
                                new OA\Property(property: "patient_identifier", type: "string"),
                            ]),
                        ]
                    )
                )
            )
        ]
    )]
    public function recentActivity(): JsonResponse
    {
        $recent = Examination::where('doctor_id', $this->doctor()->id)
            ->with([
                'patient:id,patient_identifier',
                'prediction:id,examination_id,is_lum_a,status',
            ])
            ->orderByDesc('examined_at')
            ->limit(10)
            ->get();

        return response()->json($recent);
    }
}
