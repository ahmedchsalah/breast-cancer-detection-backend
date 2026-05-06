<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Models\Patient;
use App\Models\Prediction;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InsightsController extends Controller
{
    private function doctor()
    {
        return auth()->user();
    }

    /**
     * KPI cards for the doctor's personal dashboard.
     */
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

    /**
     * My examinations per month (last 12 months) – line chart.
     */
    public function examinationsOverTime(): JsonResponse
    {
        $data = Examination::where('doctor_id', $this->doctor()->id)
            ->select(
                DB::raw("DATE_FORMAT(examined_at, '%Y-%m') as month"),
                DB::raw('COUNT(*) as count')
            )
            ->where('examined_at', '>=', now()->subYear())
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json($data);
    }

    /**
     * My prediction results distribution (Lum-A vs Non-Lum-A) – donut chart.
     */
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

    /**
     * Average confidence scores of my predictions.
     */
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

    /**
     * Patient age distribution for my patients – histogram.
     */
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

    /**
     * Recent activity feed – last 10 examinations and their prediction status.
     */
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
