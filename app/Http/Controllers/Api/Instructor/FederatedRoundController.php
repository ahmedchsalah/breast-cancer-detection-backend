<?php

namespace App\Http\Controllers\Api\Instructor;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\FlContribution;
use App\Models\FlRound;
use App\Models\FlRoundInvitation;
use App\Services\BlockchainHashingService;
use App\Services\GeminiFlAdvisorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "FlRoundObject",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer"),
        new OA\Property(property: "ai_model_id", type: "integer", nullable: true),
        new OA\Property(property: "round_number", type: "integer"),
        new OA\Property(property: "status", type: "string", enum: ["initiated", "training", "aggregating", "completed", "failed"]),
        new OA\Property(property: "global_accuracy", type: "number", format: "float", nullable: true),
        new OA\Property(property: "deadline", type: "string", format: "date-time", nullable: true),
        new OA\Property(property: "recommended_hyperparams", type: "object", nullable: true),
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

    public function index(): JsonResponse
    {
        $rounds = FlRound::with('aiModel:id,name,version')
            ->withCount('contributions')
            ->orderByDesc('round_number')
            ->paginate(20);

        return response()->json($rounds);
    }

    // ============================================================
    //  CURRENT — Get the instructor's current invitation state
    // ============================================================

    public function current(): JsonResponse
    {
        $instructor = auth()->user();

        // Find the most recent invitation for an active round
        $invitation = FlRoundInvitation::where('instructor_id', $instructor->id)
            ->whereHas('flRound', function ($q) {
                $q->whereIn('status', ['initiated', 'training', 'aggregating']);
            })
            ->with(['flRound.aiModel:id,name,version'])
            ->orderByDesc('created_at')
            ->first();

        if (!$invitation) {
            $lastCompleted = FlRound::where('status', 'completed')
                ->orderByDesc('round_number')
                ->first();
            return response()->json([
                'state' => 'no_active',
                'last_completed' => $lastCompleted ? [
                    'round_number'  => $lastCompleted->round_number,
                    'completed_at'  => $lastCompleted->ended_at,
                    'global_accuracy' => $lastCompleted->global_accuracy,
                ] : null,
            ]);
        }

        $round = $invitation->flRound;
        $stateMap = [
            'pending'   => 'invitation',
            'accepted'  => 'accepted',
            'training'  => 'training',
            'declined'  => 'declined',
            'submitted' => 'completed',
        ];

        // Aggregate counts
        $accepted  = FlRoundInvitation::where('fl_round_id', $round->id)->where('status', 'accepted')->count();
        $training  = FlRoundInvitation::where('fl_round_id', $round->id)->where('status', 'training')->count();
        $submitted = FlRoundInvitation::where('fl_round_id', $round->id)->where('status', 'submitted')->count();
        $total     = FlRoundInvitation::where('fl_round_id', $round->id)->count();

        // Check if deadline has passed
        $deadlinePassed = $round->deadline && now()->isAfter($round->deadline);

        return response()->json([
            'state'           => $stateMap[$invitation->status] ?? 'unknown',
            'round' => [
                'id'                      => $round->id,
                'round_number'            => $round->round_number,
                'status'                  => $round->status,
                'ai_model'                => $round->aiModel,
                'modality'                => $round->modality,
                'started_at'              => $round->started_at,
                'deadline'                => $round->deadline,
                'deadline_passed'         => $deadlinePassed,
                'previous_global_accuracy'=> $round->global_accuracy,
                'recommended_hyperparams' => $round->recommended_hyperparams,
                'aggregation_method'      => $round->aggregation_method,
            ],
            'invitation' => [
                'id'                    => $invitation->id,
                'status'                => $invitation->status,
                'responded_at'          => $invitation->responded_at,
                'submitted_at'          => $invitation->submitted_at,
                'local_accuracy'        => $invitation->local_accuracy,
                'local_loss'            => $invitation->local_loss,
                'weights_hash'          => $invitation->weights_hash,
                'weights_r2_key'        => $invitation->weights_r2_key,
                'hyperparams_used'      => $invitation->hyperparams_used,
                'local_sample_size'     => $invitation->local_sample_size,
                'data_inspected_at'     => $invitation->data_inspected_at,
                'training_started_at'   => $invitation->training_started_at,
                'training_completed_at' => $invitation->training_completed_at,
            ],
            'participation' => [
                'accepted'       => $accepted,
                'training'       => $training,
                'submitted'      => $submitted,
                'total_invited'  => $total,
            ],
        ]);
    }

    // ============================================================
    //  INSPECT DATA — Instructor inspects available data before training
    // ============================================================

    public function inspectData(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invitation_id' => 'required|integer|exists:fl_round_invitations,id',
        ]);

        $instructor = auth()->user();
        $invitation = FlRoundInvitation::where('id', $validated['invitation_id'])
            ->where('instructor_id', $instructor->id)
            ->first();

        if (!$invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        if (!in_array($invitation->status, ['accepted', 'training'])) {
            return response()->json(['message' => 'You must accept the invitation first.'], 422);
        }

        $round = $invitation->flRound;

        // Count available data for this instructor's organization
        $orgId = $instructor->organization_id;
        $patientCount = \App\Models\Patient::where('organization_id', $orgId)->count();
        $wsiCount = \App\Models\WsiUpload::whereHas('examination.patient', fn($q) => $q->where('organization_id', $orgId))->count();
        $examinationCount = \App\Models\Examination::whereHas('patient', fn($q) => $q->where('organization_id', $orgId))->count();

        // Get smart hyperparameters from Gemini
        $gemini = new GeminiFlAdvisorService();
        $smartHyperparams = $gemini->suggestHyperparameters([
            'modality'          => $round->modality === 'open' ? 'FULL' : 'FULL',
            'dataset_size'      => $patientCount,
            'current_round'     => $round->round_number,
            'previous_accuracy' => $round->global_accuracy,
            'model_type'        => 'a6_fusion',
            'gpu_available'     => false,
        ]);

        // Mark data as inspected
        $invitation->update(['data_inspected_at' => now()]);

        return response()->json([
            'data_summary' => [
                'patient_count'     => $patientCount,
                'wsi_count'         => $wsiCount,
                'examination_count' => $examinationCount,
                'min_samples_required' => $round->min_samples,
                'meets_minimum'     => $patientCount >= $round->min_samples,
            ],
            'recommended_hyperparams' => $smartHyperparams,
            'round_hyperparams'       => $round->recommended_hyperparams,
            'can_start_training'      => $patientCount >= $round->min_samples,
        ]);
    }

    // ============================================================
    //  START TRAINING — Instructor begins local training (async, no admin wait)
    // ============================================================

    public function startTraining(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invitation_id'    => 'required|integer|exists:fl_round_invitations,id',
            'hyperparams'      => 'nullable|array',
            'hyperparams.learning_rate'  => 'nullable|numeric',
            'hyperparams.weight_decay'   => 'nullable|numeric',
            'hyperparams.batch_size'     => 'nullable|integer|min:1',
            'hyperparams.local_epochs'   => 'nullable|integer|min:1|max:50',
            'hyperparams.dropout_rate'   => 'nullable|numeric|between:0,1',
            'hyperparams.optimizer'      => 'nullable|string',
            'local_sample_size' => 'nullable|integer|min:1',
        ]);

        $instructor = auth()->user();
        $invitation = FlRoundInvitation::where('id', $validated['invitation_id'])
            ->where('instructor_id', $instructor->id)
            ->first();

        if (!$invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        if (!in_array($invitation->status, ['accepted', 'training'])) {
            return response()->json(['message' => 'You must accept the invitation first.'], 422);
        }

        // Check deadline hasn't passed
        $round = $invitation->flRound;
        if ($round->deadline && now()->isAfter($round->deadline)) {
            return response()->json(['message' => 'The deadline for this round has passed. Training cannot start.'], 422);
        }

        // Use provided hyperparams or fall back to Gemini recommendations
        $hyperparams = $validated['hyperparams'] ?? $round->recommended_hyperparams ?? [];

        // If instructor provided custom hyperparams, validate them with Gemini
        if (!empty($validated['hyperparams']) && !empty($round->recommended_hyperparams)) {
            $gemini = new GeminiFlAdvisorService();
            $smartDefaults = $round->recommended_hyperparams;

            // Warn if instructor's choices are significantly different from recommendations
            $warnings = [];
            if (isset($validated['hyperparams']['learning_rate']) && isset($smartDefaults['learning_rate'])) {
                $ratio = $validated['hyperparams']['learning_rate'] / max($smartDefaults['learning_rate'], 1e-10);
                if ($ratio > 5 || $ratio < 0.2) {
                    $warnings[] = "Your learning rate is significantly different from the recommended value. This may hurt convergence.";
                }
            }
            if (isset($validated['hyperparams']['local_epochs']) && isset($smartDefaults['local_epochs'])) {
                if ($validated['hyperparams']['local_epochs'] > $smartDefaults['local_epochs'] * 2) {
                    $warnings[] = "Too many local epochs may cause client drift in federated learning.";
                }
            }
        }

        // Update invitation to training status
        $invitation->update([
            'status'              => FlRoundInvitation::STATUS_TRAINING,
            'training_started_at' => now(),
            'hyperparams_used'    => $hyperparams,
            'local_sample_size'   => $validated['local_sample_size'] ?? null,
        ]);

        // Update round status to training if still initiated
        if ($round->status === 'initiated') {
            $round->update(['status' => 'training']);
        }

        return response()->json([
            'message'     => 'Training started. You can submit your results when done.',
            'invitation'  => $invitation->fresh(),
            'hyperparams' => $hyperparams,
            'warnings'    => $warnings ?? [],
        ]);
    }

    // ============================================================
    //  SUBMIT CONTRIBUTION — After local training is complete
    // ============================================================

    public function submitContribution(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invitation_id'    => 'required|integer|exists:fl_round_invitations,id',
            'local_accuracy'   => 'required|numeric|between:0,1',
            'local_loss'       => 'required|numeric|min:0',
            'weights_hash'     => 'required|string|max:128',
            'weights_r2_key'   => 'required|string|max:500',
            'local_sample_size'=> 'required|integer|min:1',
        ]);

        $instructor = auth()->user();
        $invitation = FlRoundInvitation::where('id', $validated['invitation_id'])
            ->where('instructor_id', $instructor->id)
            ->first();

        if (!$invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        if (!in_array($invitation->status, ['accepted', 'training'])) {
            return response()->json(['message' => 'You must accept the invitation and start training first.'], 422);
        }

        // Record blockchain block for this contribution
        BlockchainHashingService::createContributionBlock(
            $invitation->fl_round_id,
            $instructor->organization_id ?? 0,
            $validated['weights_hash'],
            [
                'invitation_id'    => $invitation->id,
                'instructor_id'    => $instructor->id,
                'local_accuracy'   => $validated['local_accuracy'],
                'local_loss'       => $validated['local_loss'],
                'local_sample_size'=> $validated['local_sample_size'],
            ]
        );

        $invitation->update([
            'status'                => FlRoundInvitation::STATUS_SUBMITTED,
            'submitted_at'          => now(),
            'local_accuracy'        => $validated['local_accuracy'],
            'local_loss'            => $validated['local_loss'],
            'weights_hash'          => $validated['weights_hash'],
            'weights_r2_key'        => $validated['weights_r2_key'],
            'local_sample_size'     => $validated['local_sample_size'],
            'training_completed_at' => now(),
        ]);

        // Also create a FlContribution record for backward compatibility
        FlContribution::create([
            'fl_round_id'          => $invitation->fl_round_id,
            'organization_id'      => $instructor->organization_id,
            'local_sample_size'    => $validated['local_sample_size'],
            'local_accuracy_before'=> $invitation->flRound->global_accuracy ?? 0,
            'local_accuracy_after' => $validated['local_accuracy'],
            'weights_update_path'  => $validated['weights_r2_key'],
            'weights_hash'         => $validated['weights_hash'],
            'aggregation_method'   => $invitation->flRound->aggregation_method,
        ]);

        // Compute delta vs global
        $globalPrev = $invitation->flRound->global_accuracy;
        $delta = $globalPrev !== null ? round(($validated['local_accuracy'] - $globalPrev) * 100, 2) : null;

        // Check if all invited instructors have submitted — if so, aggregation can proceed
        $round = $invitation->flRound;
        $totalInvited = FlRoundInvitation::where('fl_round_id', $round->id)->count();
        $totalSubmitted = FlRoundInvitation::where('fl_round_id', $round->id)->where('status', 'submitted')->count();
        $allSubmitted = $totalSubmitted >= $totalInvited;

        return response()->json([
            'message' => 'Contribution submitted successfully.',
            'invitation' => $invitation->fresh(),
            'metrics' => [
                'local_accuracy'            => $validated['local_accuracy'],
                'previous_global_accuracy'  => $globalPrev,
                'delta_percentage_points'   => $delta,
            ],
            'aggregation' => [
                'all_contributions_in' => $allSubmitted,
                'submitted_count'      => $totalSubmitted,
                'total_invited'        => $totalInvited,
                'deadline'             => $round->deadline,
                'deadline_passed'      => $round->deadline && now()->isAfter($round->deadline),
            ],
        ]);
    }

    // ============================================================
    //  SUGGEST HYPERPARAMS — Get Gemini recommendations for this round
    // ============================================================

    public function suggestHyperparams(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'round_id' => 'required|integer|exists:fl_rounds,id',
        ]);

        $instructor = auth()->user();
        $round = FlRound::findOrFail($validated['round_id']);

        // Count available data
        $orgId = $instructor->organization_id;
        $patientCount = \App\Models\Patient::where('organization_id', $orgId)->count();

        $gemini = new GeminiFlAdvisorService();
        $hyperparams = $gemini->suggestHyperparameters([
            'modality'          => $round->modality === 'open' ? 'FULL' : 'FULL',
            'dataset_size'      => $patientCount,
            'current_round'     => $round->round_number,
            'previous_accuracy' => $round->global_accuracy,
            'model_type'        => 'a6_fusion',
            'gpu_available'     => false,
        ]);

        return response()->json([
            'hyperparams' => $hyperparams,
            'round_defaults' => $round->recommended_hyperparams,
        ]);
    }

    // ============================================================
    //  SHOW
    // ============================================================

    public function show(FlRound $flRound): JsonResponse
    {
        $flRound->load([
            'aiModel:id,name,version',
            'contributions.organization:id,name,type',
        ]);

        return response()->json($flRound);
    }

    // ============================================================
    //  STORE (kept for backward compatibility)
    // ============================================================

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ai_model_id' => 'required|integer|exists:ai_models,id',
        ]);

        $lastRound   = FlRound::where('ai_model_id', $validated['ai_model_id'])->max('round_number');
        $roundNumber = ($lastRound ?? 0) + 1;

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
    //  COMPLETE (kept for backward compatibility)
    // ============================================================

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
