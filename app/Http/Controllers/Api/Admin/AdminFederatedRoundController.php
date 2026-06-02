<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FlRound;
use App\Services\BlockchainHashingService;
use App\Services\GeminiFlAdvisorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "AdminFlRoundObject",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer"),
        new OA\Property(property: "ai_model_id", type: "integer", nullable: true),
        new OA\Property(property: "round_number", type: "integer"),
        new OA\Property(property: "modality", type: "string"),
        new OA\Property(property: "title", type: "string", nullable: true),
        new OA\Property(property: "description", type: "string", nullable: true),
        new OA\Property(property: "min_samples", type: "integer"),
        new OA\Property(property: "status", type: "string", enum: ["initiated", "training", "aggregating", "completed", "failed"]),
        new OA\Property(property: "global_accuracy", type: "number", format: "float", nullable: true),
        new OA\Property(property: "global_loss", type: "number", format: "float", nullable: true),
        new OA\Property(property: "deadline", type: "string", format: "date-time", nullable: true),
        new OA\Property(property: "aggregation_method", type: "string"),
        new OA\Property(property: "recommended_hyperparams", type: "object", nullable: true),
        new OA\Property(property: "blockchain_receipt", type: "object", nullable: true),
        new OA\Property(property: "aggregated_weights_r2_key", type: "string", nullable: true),
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

    public function index(): JsonResponse
    {
        $rounds = FlRound::with('aiModel:id,name,version')
            ->withCount('contributions')
            ->orderByDesc('round_number')
            ->paginate(20);

        return response()->json($rounds);
    }

    // ============================================================
    //  STORE — Create a new FL round with deadline & smart hyperparams
    // ============================================================

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ai_model_id'         => 'nullable|integer|exists:ai_models,id',
            'modality'            => 'nullable|in:open,image_only,clinical_only,multimodal',
            'title'               => 'nullable|string|max:200',
            'description'         => 'nullable|string|max:2000',
            'min_samples'         => 'nullable|integer|min:1|max:10000',
            'deadline'            => 'required|date|after:now',
            'aggregation_method'  => 'nullable|in:fedavg_weighted,fedavg_accuracy_weighted,robust',
        ]);

        // Check no active round exists globally
        if (FlRound::whereIn('status', ['initiated', 'training', 'aggregating'])->exists()) {
            return response()->json(['message' => 'There is already an active FL round. Cancel or complete it before opening a new one.'], 422);
        }

        // Auto-set round_number as global max+1
        $lastRound   = FlRound::max('round_number');
        $roundNumber = ($lastRound ?? 0) + 1;

        // Get smart hyperparameter recommendations from Gemini
        $gemini = new GeminiFlAdvisorService();
        $recommendedHyperparams = $gemini->suggestHyperparameters([
            'modality'          => $validated['modality'] ?? 'open',
            'dataset_size'      => $validated['min_samples'] ?? 20,
            'current_round'     => $roundNumber,
            'model_type'        => 'a6_fusion',
            'gpu_available'     => false,
        ]);

        $round = FlRound::create([
            'ai_model_id'             => $validated['ai_model_id'] ?? null,
            'round_number'            => $roundNumber,
            'modality'                => $validated['modality'] ?? 'open',
            'title'                   => $validated['title'] ?? "Round #{$roundNumber}",
            'description'             => $validated['description'] ?? null,
            'min_samples'             => $validated['min_samples'] ?? 20,
            'status'                  => 'initiated',
            'started_at'              => now(),
            'deadline'                => $validated['deadline'],
            'aggregation_method'      => $validated['aggregation_method'] ?? 'fedavg_weighted',
            'recommended_hyperparams' => $recommendedHyperparams,
        ]);

        // Initialize blockchain ledger for this round (genesis block)
        BlockchainHashingService::createContributionBlock(
            $round->id,
            0, // Genesis block — no real org
            str_repeat('0', 64),
            ['type' => 'genesis']
        );

        // Send invitations to all instructors with active organizations
        try {
            $instructors = \App\Models\User::role('instructor')
                ->whereNotNull('organization_id')
                ->whereHas('organization', fn ($q) => $q->where('status', 'active'))
                ->where('is_active', true)
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
                    Log::warning("[FL] Email failed for instructor {$instructor->id}: {$me->getMessage()}");
                }
            }

            Log::info("[FL] Round #{$round->round_number} created, {$instructors->count()} invitations sent. Deadline: {$validated['deadline']}");
        } catch (\Throwable $e) {
            Log::warning("[FL] Failed to send invitations: {$e->getMessage()}");
        }

        return response()->json($round->load('aiModel:id,name,version'), 201);
    }

    // ============================================================
    //  SHOW
    // ============================================================

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
                'weights_hash',
                'aggregation_method',
                'created_at'
            )->with('organization:id,name'),
            'invitations' => fn ($q) => $q->select(
                'id',
                'fl_round_id',
                'instructor_id',
                'status',
                'responded_at',
                'submitted_at',
                'local_accuracy',
                'local_loss',
                'weights_hash',
                'local_sample_size',
                'data_inspected_at',
                'training_started_at',
                'training_completed_at',
            )->with('instructor:id,name,email,organization_id', 'instructor.organization:id,name'),
        ]);

        // Add participation stats
        $invitations = $flRound->invitations;
        $stats = [
            'total_invited'  => $invitations->count(),
            'accepted'       => $invitations->where('status', 'accepted')->count(),
            'training'       => $invitations->where('status', 'training')->count(),
            'submitted'      => $invitations->where('status', 'submitted')->count(),
            'declined'       => $invitations->where('status', 'declined')->count(),
            'pending'        => $invitations->where('status', 'pending')->count(),
        ];

        return response()->json([
            'round' => $flRound,
            'participation' => $stats,
            'blockchain_verified' => BlockchainHashingService::verifyChain($flRound->id),
        ]);
    }

    // ============================================================
    //  TRIGGER AGGREGATION — manually or after deadline
    // ============================================================

    public function triggerAggregation(FlRound $flRound): JsonResponse
    {
        if (!in_array($flRound->status, ['initiated', 'training'])) {
            return response()->json(['message' => 'This round is not in a state that allows aggregation.'], 422);
        }

        // Get all submitted invitations
        $submittedInvitations = \App\Models\FlRoundInvitation::where('fl_round_id', $flRound->id)
            ->where('status', 'submitted')
            ->get();

        if ($submittedInvitations->isEmpty()) {
            return response()->json(['message' => 'No submitted contributions to aggregate.'], 422);
        }

        // Mark round as aggregating
        $flRound->update(['status' => 'aggregating']);

        // Build contributions list for the FL aggregation space
        $contributions = $submittedInvitations->map(fn ($inv) => [
            'round_id'         => $flRound->id,
            'invitation_id'    => $inv->id,
            'instructor_id'    => $inv->instructor_id,
            'organization_id'  => $inv->instructor->organization_id ?? 0,
            'local_accuracy'   => $inv->local_accuracy,
            'local_loss'       => $inv->local_loss,
            'local_sample_size'=> $inv->local_sample_size ?? 0,
            'weights_r2_key'   => $inv->weights_r2_key ?? '',
            'weights_hash'     => $inv->weights_hash ?? '',
            'modality'         => $flRound->modality === 'open' ? 'FULL' : 'FULL',
        ])->values()->toArray();

        // Call the FL aggregation space
        $aggUrl = rtrim(config('services.fl_aggregation.url'), '/');

        try {
            $response = Http::timeout(120)->post("{$aggUrl}/aggregate", [
                'round_id'        => $flRound->id,
                'modality'        => $flRound->modality,
                'contributions'   => $contributions,
                'webhook_url'     => url('/api/internal/fl/aggregation-result'),
                'internal_secret' => config('services.fl_aggregation.secret'),
            ]);

            if ($response->successful()) {
                $result = $response->json();

                // Record blockchain aggregation block
                if (!empty($result['aggregated_weights_hash'])) {
                    $contributionHashes = $submittedInvitations->pluck('weights_hash')->toArray();
                    BlockchainHashingService::createAggregationBlock(
                        $flRound->id,
                        $result['aggregated_weights_hash'],
                        $contributionHashes,
                        $flRound->aggregation_method,
                        [
                            'n_contributions' => $result['n_contributions'] ?? count($contributions),
                            'avg_accuracy'    => $result['global_accuracy'] ?? null,
                        ]
                    );
                }

                // Update round with aggregation results
                $flRound->update([
                    'status'                    => 'completed',
                    'global_accuracy'           => $result['global_accuracy'] ?? null,
                    'global_loss'               => $result['global_loss'] ?? null,
                    'aggregated_weights_r2_key' => $result['aggregated_weights_r2_key'] ?? null,
                    'aggregated_weights_hash'   => $result['aggregated_weights_hash'] ?? null,
                    'ended_at'                  => now(),
                ]);

                return response()->json([
                    'message' => "Round #{$flRound->round_number} aggregated successfully.",
                    'result'  => $result,
                    'round'   => $flRound->fresh()->load('aiModel:id,name,version'),
                ]);
            }

            Log::error('[FL] Aggregation space returned error: ' . $response->body());
            return response()->json([
                'message' => 'Aggregation service returned an error.',
                'detail'  => $response->body(),
            ], 500);

        } catch (\Throwable $e) {
            Log::error('[FL] Aggregation call failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to reach aggregation service.',
                'detail'  => $e->getMessage(),
            ], 502);
        }
    }

    // ============================================================
    //  COMPLETE — manual completion (for when aggregation was done externally)
    // ============================================================

    public function complete(Request $request, FlRound $flRound): JsonResponse
    {
        if ($flRound->status === 'completed') {
            return response()->json(['message' => 'This round is already completed.'], 422);
        }

        $validated = $request->validate([
            'global_accuracy'           => 'required|numeric|between:0,1',
            'global_loss'               => 'nullable|numeric|min:0',
            'aggregated_weights_r2_key' => 'nullable|string|max:500',
            'aggregated_weights_hash'   => 'nullable|string|max:128',
        ]);

        $flRound->update([
            'status'                    => 'completed',
            'global_accuracy'           => $validated['global_accuracy'],
            'global_loss'               => $validated['global_loss'] ?? null,
            'aggregated_weights_r2_key' => $validated['aggregated_weights_r2_key'] ?? null,
            'aggregated_weights_hash'   => $validated['aggregated_weights_hash'] ?? null,
            'ended_at'                  => now(),
        ]);

        return response()->json([
            'message' => "Round #{$flRound->round_number} completed.",
            'round'   => $flRound->fresh()->load('aiModel:id,name,version'),
        ]);
    }

    // ============================================================
    //  CANCEL
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
    //  DESTROY
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
