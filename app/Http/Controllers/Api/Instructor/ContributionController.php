<?php

namespace App\Http\Controllers\Api\Instructor;

use App\Http\Controllers\Controller;
use App\Models\FlContribution;
use App\Models\FlRound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContributionController extends Controller
{
    /**
     * List all contributions for a given FL round.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'fl_round_id' => 'required|integer|exists:fl_rounds,id',
        ]);

        $contributions = FlContribution::where('fl_round_id', $request->fl_round_id)
            ->with('organization:id,name,type', 'flRound:id,round_number,status')
            ->get();

        return response()->json($contributions);
    }

    /**
     * Record a contribution from an organization for a round.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fl_round_id'          => 'required|integer|exists:fl_rounds,id',
            'organization_id'      => 'required|integer|exists:organizations,id',
            'local_sample_size'    => 'required|integer|min:1',
            'local_accuracy_before'=> 'required|numeric|between:0,1',
            'local_accuracy_after' => 'required|numeric|between:0,1',
            'weights_update_path'  => 'required|string|max:500',
        ]);

        // Prevent duplicate contribution for same org in same round
        if (FlContribution::where('fl_round_id', $validated['fl_round_id'])
            ->where('organization_id', $validated['organization_id'])
            ->exists()) {
            return response()->json(['message' => 'This organization has already contributed to this round.'], 422);
        }

        $contribution = FlContribution::create($validated);

        return response()->json($contribution->load('organization:id,name'), 201);
    }
}
