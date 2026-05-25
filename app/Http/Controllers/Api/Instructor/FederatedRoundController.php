<?php

namespace App\Http\Controllers\Api\Instructor;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\FlContribution;
use App\Models\FlRound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "FlRoundObject",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer"),
        new OA\Property(property: "ai_model_id", type: "integer"),
        new OA\Property(property: "round_number", type: "integer"),
        new OA\Property(property: "status", type: "string", enum: ["pending", "in_progress", "completed", "failed"]),
        new OA\Property(property: "global_accuracy", type: "number", format: "float", nullable: true),
        new OA\Property(property: "started_at", type: "string", format: "date-time", nullable: true),
        new OA\Property(property: "ended_at", type: "string", format: "date-time", nullable: true),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
    ]
)]
class FederatedRoundController extends Controller
{
    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/instructor/fl-rounds",
        tags: ["Instructor — FL Rounds"],
        summary: "List all FL rounds",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated list of FL rounds",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "data", type: "array", items: new OA\Items(ref: "#/components/schemas/FlRoundObject")),
                        new OA\Property(property: "current_page", type: "integer"),
                        new OA\Property(property: "total", type: "integer"),
                    ]
                )
            )
        ]
    )]
    public function index(): JsonResponse
    {
        $rounds = FlRound::with('aiModel:id,name,version')
            ->withCount('contributions')
            ->orderByDesc('round_number')
            ->paginate(20);

        return response()->json($rounds);
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/instructor/fl-rounds/{id}",
        tags: ["Instructor — FL Rounds"],
        summary: "Show a specific round with all organization contributions",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "FL round details",
                content: new OA\JsonContent(ref: "#/components/schemas/FlRoundObject")
            ),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(FlRound $flRound): JsonResponse
    {
        $flRound->load([
            'aiModel:id,name,version',
            'contributions.organization:id,name,type',
        ]);

        return response()->json($flRound);
    }

    // ============================================================
    //  STORE
    // ============================================================

    #[OA\Post(
        path: "/instructor/fl-rounds",
        tags: ["Instructor — FL Rounds"],
        summary: "Open a new FL round for a given model",
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["ai_model_id"],
                properties: [
                    new OA\Property(property: "ai_model_id", type: "integer"),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "FL round created", content: new OA\JsonContent(ref: "#/components/schemas/FlRoundObject")),
            new OA\Response(response: 422, description: "Active FL round already exists for this model"),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ai_model_id' => 'required|integer|exists:ai_models,id',
        ]);

        $lastRound   = FlRound::where('ai_model_id', $validated['ai_model_id'])->max('round_number');
        $roundNumber = ($lastRound ?? 0) + 1;

        // Block if there is already an open round for this model
        if (FlRound::where('ai_model_id', $validated['ai_model_id'])->whereIn('status', ['initiated', 'training', 'aggregating'])->exists()) {
            return response()->json(['message' => 'There is already an active FL round for this model.'], 422);
        }

        $round = FlRound::create([
            'ai_model_id'  => $validated['ai_model_id'],
            'round_number' => $roundNumber,
            'status'       => 'initiated',
            'started_at'   => now(),
        ]);

        return response()->json($round, 201);
    }

    // ============================================================
    //  COMPLETE
    // ============================================================

    #[OA\Post(
        path: "/instructor/fl-rounds/{id}/complete",
        tags: ["Instructor — FL Rounds"],
        summary: "Complete an FL round and record the aggregated global accuracy",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["global_accuracy"],
                properties: [
                    new OA\Property(property: "global_accuracy", type: "number", format: "float"),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "FL round completed"),
            new OA\Response(response: 422, description: "Validation error / Round already completed"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
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
