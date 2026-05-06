<?php

namespace App\Http\Controllers\Api\Instructor;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\FlRound;
use App\Models\Prediction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InsightsController extends Controller
{
    /**
     * FL platform KPI cards.
     */
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

    /**
     * Global accuracy across FL rounds – line chart.
     */
    public function accuracyOverRounds(): JsonResponse
    {
        $data = FlRound::with('aiModel:id,name,version')
            ->where('status', 'completed')
            ->orderBy('round_number')
            ->get(['id', 'ai_model_id', 'round_number', 'global_accuracy', 'started_at', 'ended_at']);

        return response()->json($data);
    }

    /**
     * Contributions per organization per round – stacked bar chart.
     */
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
