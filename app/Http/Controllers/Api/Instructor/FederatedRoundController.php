<?php

namespace App\Http\Controllers\Api\Instructor;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\FlContribution;
use App\Models\FlRound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FederatedRoundController extends Controller
{
    /**
     * List all FL rounds.
     */
    public function index(): JsonResponse
    {
        $rounds = FlRound::with('aiModel:id,name,version')
            ->withCount('contributions')
            ->orderByDesc('round_number')
            ->paginate(20);

        return response()->json($rounds);
    }

    /**
     * Show a specific round with all organization contributions.
     */
    public function show(FlRound $flRound): JsonResponse
    {
        $flRound->load([
            'aiModel:id,name,version',
            'contributions.organization:id,name,type',
        ]);

        return response()->json($flRound);
    }

    /**
     * Open a new FL round for a given model.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ai_model_id' => 'required|integer|exists:ai_models,id',
        ]);

        $lastRound   = FlRound::where('ai_model_id', $validated['ai_model_id'])->max('round_number');
        $roundNumber = ($lastRound ?? 0) + 1;

        // Block if there is already an open round for this model
        if (FlRound::where('ai_model_id', $validated['ai_model_id'])->whereIn('status', ['pending', 'in_progress'])->exists()) {
            return response()->json(['message' => 'There is already an active FL round for this model.'], 422);
        }

        $round = FlRound::create([
            'ai_model_id'  => $validated['ai_model_id'],
            'round_number' => $roundNumber,
            'status'       => 'pending',
            'started_at'   => now(),
        ]);

        return response()->json($round, 201);
    }

    /**
     * Complete an FL round and record the aggregated global accuracy.
     */
    public function complete(Request $request, FlRound $flRound): JsonResponse
    {
        if ($flRound->status === 'completed') {
            return response()->json(['message' => 'This round is already completed.'], 422);
        }

        $validated = $request->validate([
            'global_accuracy' => 'required|numeric|between:0,1',
        ]);

        $flRound->update([
            'status'          => 'completed',
            'global_accuracy' => $validated['global_accuracy'],
            'ended_at'        => now(),
        ]);

        return response()->json(['message' => "Round #{$flRound->round_number} completed.", 'round' => $flRound->fresh()]);
    }
}
