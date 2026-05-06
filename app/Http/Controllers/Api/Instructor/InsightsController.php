<?php

namespace App\Http\Controllers\Api\Instructor;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\FlRound;
use App\Models\Prediction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class InsightsController extends Controller
{
    // ============================================================
    //  KPIs
    // ============================================================

    #[OA\Get(
        path: "/instructor/insights/kpis",
        tags: ["Instructor — Insights"],
        summary: "FL platform KPI cards",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Instructor KPIs",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "total_fl_rounds", type: "integer"),
                        new OA\Property(property: "completed_fl_rounds", type: "integer"),
                        new OA\Property(property: "active_ai_models", type: "integer"),
                        new OA\Property(property: "total_ai_models", type: "integer"),
                        new OA\Property(property: "latest_global_accuracy", type: "number", format: "float", nullable: true),
                        new OA\Property(property: "latest_round_number", type: "integer", nullable: true),
                        new OA\Property(property: "total_predictions_served", type: "integer"),
                    ]
                )
            )
        ]
    )]
    public function kpis(): JsonResponse
    {
        $latestRound = FlRound::where('status', 'completed')->orderByDesc('round_number')->first();

        return response()->json([
            'total_fl_rounds'          => FlRound::count(),
            'completed_fl_rounds'      => FlRound::where('status', 'completed')->count(),
            'active_ai_models'         => AiModel::where('is_active', true)->count(),
            'total_ai_models'          => AiModel::count(),
            'latest_global_accuracy'   => $latestRound?->global_accuracy,
            'latest_round_number'      => $latestRound?->round_number,
            'total_predictions_served' => Prediction::where('status', 'completed')->count(),
        ]);
    }

    // ============================================================
    //  Accuracy Over Rounds
    // ============================================================

    #[OA\Get(
        path: "/instructor/insights/accuracy-over-rounds",
        tags: ["Instructor — Insights"],
        summary: "Global accuracy across FL rounds",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Line chart data",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: "id", type: "integer"),
                            new OA\Property(property: "ai_model_id", type: "integer"),
                            new OA\Property(property: "round_number", type: "integer"),
                            new OA\Property(property: "global_accuracy", type: "number", format: "float"),
                            new OA\Property(property: "started_at", type: "string", format: "date-time"),
                            new OA\Property(property: "ended_at", type: "string", format: "date-time"),
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
    public function accuracyOverRounds(): JsonResponse
    {
        $data = FlRound::with('aiModel:id,name,version')
            ->where('status', 'completed')
            ->orderBy('round_number')
            ->get(['id', 'ai_model_id', 'round_number', 'global_accuracy', 'started_at', 'ended_at']);

        return response()->json($data);
    }

    // ============================================================
    //  Contributions Per Round
    // ============================================================

    #[OA\Get(
        path: "/instructor/insights/contributions-per-round",
        tags: ["Instructor — Insights"],
        summary: "Contributions per organization per round",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Stacked bar chart data",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: "fl_round_id", type: "integer"),
                            new OA\Property(property: "organization_id", type: "integer"),
                            new OA\Property(property: "avg_acc_before", type: "number", format: "float"),
                            new OA\Property(property: "avg_acc_after", type: "number", format: "float"),
                            new OA\Property(property: "total_samples", type: "integer"),
                            new OA\Property(property: "organization", type: "object", properties: [
                                new OA\Property(property: "id", type: "integer"),
                                new OA\Property(property: "name", type: "string"),
                            ]),
                            new OA\Property(property: "fl_round", type: "object", properties: [
                                new OA\Property(property: "id", type: "integer"),
                                new OA\Property(property: "round_number", type: "integer"),
                            ]),
                        ]
                    )
                )
            )
        ]
    )]
    public function contributionsPerRound(): JsonResponse
    {
        $data = \App\Models\FlContribution::with('organization:id,name', 'flRound:id,round_number')
            ->select('fl_round_id', 'organization_id',
                DB::raw('AVG(local_accuracy_before) as avg_acc_before'),
                DB::raw('AVG(local_accuracy_after) as avg_acc_after'),
                DB::raw('SUM(local_sample_size) as total_samples')
            )
            ->groupBy('fl_round_id', 'organization_id')
            ->get();

        return response()->json($data);
    }
}
