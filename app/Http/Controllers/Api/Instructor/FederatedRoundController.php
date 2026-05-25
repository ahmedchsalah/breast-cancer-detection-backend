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
    //  CURRENT INVITATION (for the logged-in instructor)
    // ============================================================

    public function current(): JsonResponse
    {
        $instructor = auth()->user();

        // Find the most recent invitation for an active round
        $invitation = \App\Models\FlRoundInvitation::where('instructor_id', $instructor->id)
            ->whereHas('flRound', function ($q) {
                $q->whereIn('status', ['initiated', 'training', 'aggregating']);
            })
            ->with(['flRound.aiModel:id,name,version'])
            ->orderByDesc('created_at')
            ->first();

        if (!$invitation) {
            // No active invitation. Return last completed round info.
            $lastCompleted = FlRound::where('status', 'completed')
                ->orderByDesc('round_number')
                ->first();
            return response()->json([
                'state' => 'no_active',
                'last_completed' => $lastCompleted ? [
                    'round_number' => $lastCompleted->round_number,
                    'completed_at' => $lastCompleted->ended_at,
                    'global_accuracy' => $lastCompleted->global_accuracy,
                ] : null,
            ]);
        }

        $round = $invitation->flRound;
        $stateMap = [
            'pending' => 'invitation',
            'accepted' => 'accepted',
            'declined' => 'declined',
            'submitted' => 'completed',
        ];

        // Aggregate counts
        $accepted = \App\Models\FlRoundInvitation::where('fl_round_id', $round->id)->where('status', 'accepted')->count();
        $submitted = \App\Models\FlRoundInvitation::where('fl_round_id', $round->id)->where('status', 'submitted')->count();
        $total = \App\Models\FlRoundInvitation::where('fl_round_id', $round->id)->count();

        return response()->json([
            'state' => $stateMap[$invitation->status] ?? 'unknown',
            'round' => [
                'id' => $round->id,
                'round_number' => $round->round_number,
                'status' => $round->status,
                'ai_model' => $round->aiModel,
                'started_at' => $round->started_at,
                'previous_global_accuracy' => $round->global_accuracy,
            ],
            'invitation' => [
                'id' => $invitation->id,
                'status' => $invitation->status,
                'responded_at' => $invitation->responded_at,
                'submitted_at' => $invitation->submitted_at,
                'local_accuracy' => $invitation->local_accuracy,
                'local_loss' => $invitation->local_loss,
                'weights_hash' => $invitation->weights_hash,
            ],
            'participation' => [
                'accepted' => $accepted,
                'submitted' => $submitted,
                'total_invited' => $total,
            ],
        ]);
    }

    // ============================================================
    //  SUBMIT CONTRIBUTION (after local training)
    // ============================================================

    public function submitContribution(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invitation_id' => 'required|integer|exists:fl_round_invitations,id',
            'local_accuracy' => 'required|numeric|between:0,1',
            'local_loss' => 'required|numeric|min:0',
            'weights_hash' => 'required|string|max:128',
            'samples_used' => 'nullable|integer|min:0',
        ]);

        $instructor = auth()->user();
        $invitation = \App\Models\FlRoundInvitation::where('id', $validated['invitation_id'])
            ->where('instructor_id', $instructor->id)
            ->first();

        if (!$invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        if ($invitation->status !== 'accepted') {
            return response()->json(['message' => 'You must accept the invitation first.'], 422);
        }

        $invitation->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'local_accuracy' => $validated['local_accuracy'],
            'local_loss' => $validated['local_loss'],
            'weights_hash' => $validated['weights_hash'],
        ]);

        // Compute delta vs global
        $globalPrev = $invitation->flRound->global_accuracy;
        $delta = $globalPrev !== null ? round(($validated['local_accuracy'] - $globalPrev) * 100, 2) : null;

        return response()->json([
            'message' => 'Contribution submitted successfully.',
            'invitation' => $invitation->fresh(),
            'metrics' => [
                'local_accuracy' => $validated['local_accuracy'],
                'previous_global_accuracy' => $globalPrev,
                'delta_percentage_points' => $delta,
            ],
        ]);
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
