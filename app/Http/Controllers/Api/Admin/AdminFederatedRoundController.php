<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FlRound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "AdminFlRoundObject",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer"),
        new OA\Property(property: "ai_model_id", type: "integer"),
        new OA\Property(property: "round_number", type: "integer"),
        new OA\Property(property: "status", type: "string", enum: ["pending", "in_progress", "completed", "failed"]),
        new OA\Property(property: "global_accuracy", type: "number", format: "float", nullable: true),
        new OA\Property(property: "started_at", type: "string", format: "date-time", nullable: true),
        new OA\Property(property: "ended_at", type: "string", format: "date-time", nullable: true),
        new OA\Property(property: "contributions_count", type: "integer"),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
    ]
)]
class AdminFederatedRoundController extends Controller
{
    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/admin/federated-rounds",
        tags: ["Admin — Federated Rounds"],
        summary: "List all FL rounds with pagination",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated list of FL rounds",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "data", type: "array", items: new OA\Items(ref: "#/components/schemas/AdminFlRoundObject")),
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
    //  STORE
    // ============================================================

    #[OA\Post(
        path: "/admin/federated-rounds",
        tags: ["Admin — Federated Rounds"],
        summary: "Create a new FL round for a given AI model",
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
            new OA\Response(response: 201, description: "FL round created", content: new OA\JsonContent(ref: "#/components/schemas/AdminFlRoundObject")),
            new OA\Response(response: 422, description: "Active FL round already exists for this model or validation error"),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ai_model_id' => 'nullable|integer|exists:ai_models,id',
            'modality'    => 'nullable|in:open,image_only,clinical_only,multimodal',
            'title'       => 'nullable|string|max:200',
            'description' => 'nullable|string|max:2000',
            'min_samples' => 'nullable|integer|min:1|max:10000',
        ]);

        // Check no active round exists globally (not per-model anymore)
        if (FlRound::whereIn('status', ['initiated', 'training', 'aggregating'])->exists()) {
            return response()->json(['message' => 'There is already an active FL round. Cancel or complete it before opening a new one.'], 422);
        }

        // Auto-set round_number as global max+1
        $lastRound   = FlRound::max('round_number');
        $roundNumber = ($lastRound ?? 0) + 1;

        $round = FlRound::create([
            'ai_model_id'  => $validated['ai_model_id'] ?? null,
            'round_number' => $roundNumber,
            'modality'     => $validated['modality'] ?? 'open',
            'title'        => $validated['title'] ?? "Round #{$roundNumber}",
            'description'  => $validated['description'] ?? null,
            'min_samples'  => $validated['min_samples'] ?? 20,
            'status'       => 'initiated',
            'started_at'   => now(),
        ]);

        // Send invitations to all instructors with active organizations
        try {
            $instructors = \App\Models\User::role('instructor')
                ->whereNotNull('organization_id')
                ->whereHas('organization', fn ($q) => $q->where('status', 'active'))
                ->where('email_verified_at', '!=', null)
                ->get();

            $frontendUrl = rtrim(config('app.frontend_url') ?? env('FRONTEND_URL', 'https://brecai-fed-react.vercel.app'), '/');

            foreach ($instructors as $instructor) {
                $invitation = \App\Models\FlRoundInvitation::create([
                    'fl_round_id'   => $round->id,
                    'instructor_id' => $instructor->id,
                ]);

                $approveUrl = "{$frontendUrl}/fl-invite/{$invitation->token}";

                try {
                    \Illuminate\Support\Facades\Mail::to($instructor->email)
                        ->send(new \App\Mail\FlRoundInvitationMail($invitation, $instructor, $approveUrl));
                } catch (\Throwable $me) {
                    \Illuminate\Support\Facades\Log::warning("[FL] Email failed for instructor {$instructor->id}: {$me->getMessage()}");
                }
            }

            \Illuminate\Support\Facades\Log::info("[FL] Round #{$round->round_number} created, {$instructors->count()} invitations sent.");
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("[FL] Failed to send invitations: {$e->getMessage()}");
        }

        return response()->json($round->load('aiModel:id,name,version'), 201);
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/admin/federated-rounds/{id}",
        tags: ["Admin — Federated Rounds"],
        summary: "Show a specific FL round with all contributions",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "FL round details with contributions",
                content: new OA\JsonContent(ref: "#/components/schemas/AdminFlRoundObject")
            ),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(FlRound $flRound): JsonResponse
    {
        $flRound->load([
            'aiModel:id,name,version',
            'contributions' => fn ($q) => $q->select(
                'id',
                'fl_round_id',
                'organization_id',
                'local_sample_size',
                'local_accuracy_before',
                'local_accuracy_after',
                'created_at'
            )->with('organization:id,name'),
        ]);

        return response()->json($flRound);
    }

    // ============================================================
    //  COMPLETE
    // ============================================================

    #[OA\Post(
        path: "/admin/federated-rounds/{id}/complete",
        tags: ["Admin — Federated Rounds"],
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
            new OA\Response(response: 422, description: "Round already completed or validation error"),
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

        return response()->json([
            'message' => "Round #{$flRound->round_number} completed.",
            'round'   => $flRound->fresh()->load('aiModel:id,name,version'),
        ]);
    }

    // ============================================================
    //  CANCEL — admin can cancel any non-completed round
    // ============================================================

    public function cancel(FlRound $flRound): JsonResponse
    {
        if ($flRound->status === 'completed') {
            return response()->json(['message' => 'Cannot cancel a completed round.'], 422);
        }

        $flRound->update([
            'status'   => 'failed',
            'ended_at' => now(),
        ]);

        return response()->json([
            'message' => "Round #{$flRound->round_number} cancelled.",
            'round'   => $flRound->fresh(),
        ]);
    }

    // ============================================================
    //  DESTROY — hard delete (only failed/cancelled rounds)
    // ============================================================

    public function destroy(FlRound $flRound): JsonResponse
    {
        if (in_array($flRound->status, ['training', 'aggregating'])) {
            return response()->json(['message' => 'Cannot delete an active round. Cancel it first.'], 422);
        }

        $flRound->delete();

        return response()->json(['message' => 'Round deleted.']);
    }
}
