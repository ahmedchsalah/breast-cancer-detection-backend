<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\FlRoundInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, token-based controller for FL round invitations.
 * Accessed via the magic link emailed to the instructor.
 */
class FlInvitationController extends Controller
{
    /**
     * GET /api/public/fl-invite/{token}
     * Returns invitation details for the approval page.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = FlRoundInvitation::where('token', $token)
            ->with(['flRound.aiModel:id,name,version', 'instructor:id,name,email,organization_id', 'instructor.organization:id,name'])
            ->first();

        if (!$invitation) {
            return response()->json(['message' => 'Invitation not found or expired.'], 404);
        }

        return response()->json([
            'invitation' => [
                'id' => $invitation->id,
                'token' => $invitation->token,
                'status' => $invitation->status,
                'responded_at' => $invitation->responded_at,
            ],
            'round' => [
                'id' => $invitation->flRound->id,
                'round_number' => $invitation->flRound->round_number,
                'status' => $invitation->flRound->status,
                'started_at' => $invitation->flRound->started_at,
                'global_accuracy' => $invitation->flRound->global_accuracy,
                'ai_model' => $invitation->flRound->aiModel,
            ],
            'instructor' => [
                'name' => $invitation->instructor->name,
                'email' => $invitation->instructor->email,
                'organization' => $invitation->instructor->organization?->name,
            ],
        ]);
    }

    /**
     * POST /api/public/fl-invite/{token}/respond
     * Body: { decision: "accept" | "decline" }
     */
    public function respond(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'decision' => 'required|in:accept,decline',
        ]);

        $invitation = FlRoundInvitation::where('token', $token)->first();
        if (!$invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        if (!in_array($invitation->flRound->status, ['initiated', 'training'])) {
            return response()->json(['message' => 'This round is no longer accepting responses.'], 422);
        }

        $invitation->update([
            'status' => $validated['decision'] === 'accept'
                ? FlRoundInvitation::STATUS_ACCEPTED
                : FlRoundInvitation::STATUS_DECLINED,
            'responded_at' => now(),
        ]);

        return response()->json([
            'message' => 'Response recorded. Thank you.',
            'status' => $invitation->status,
        ]);
    }
}
