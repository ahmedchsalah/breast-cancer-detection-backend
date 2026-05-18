<?php

namespace App\Http\Controllers\Api\OrgManager;

use App\Http\Controllers\Controller;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    private function org()
    {
        return auth()->user()->load('organization.plan');
    }

    // GET /org/invitations
    public function index(): JsonResponse
    {
        $invitations = Invitation::where('organization_id', auth()->user()->organization_id)
            ->with('organization:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($inv) {
                $inv->status = $inv->isValid() ? 'pending' : 'expired';
                return $inv;
            });

        return response()->json($invitations);
    }

    // POST /org/invitations
    public function store(Request $request): JsonResponse
    {
        $user = $this->org();
        $org  = $user->organization;
        $plan = $org->plan;

        $validated = $request->validate([
            'email' => 'required|email|unique:invitations,email|unique:users,email',
            'role'  => 'required|in:doctor,instructor',
        ]);

        // Plan checks
        if ($validated['role'] === 'instructor') {
            // Instructor invitations require FL contribution to be allowed on the plan.
            // fl_contribution_allowed = org can participate in federated learning → needs an instructor.
            // instructor_allowed is a secondary explicit flag; either one being true is sufficient.
            $canInviteInstructor = $plan && ($plan->fl_contribution_allowed || $plan->instructor_allowed);
            if (!$canInviteInstructor) {
                return response()->json([
                    'message' => 'Your current plan does not include federated learning access. Please upgrade to a plan that includes FL contribution to invite instructors.',
                    'reason'  => 'plan_no_instructor',
                ], 422);
            }
        }

        if ($validated['role'] === 'doctor') {
            if ($plan && $plan->max_doctors !== -1) {
                $activeDoctors = User::where('organization_id', $org->id)
                    ->where('is_active', true)
                    ->role('doctor')
                    ->count();
                if ($activeDoctors >= $plan->max_doctors) {
                    return response()->json([
                        'message' => "Your plan allows a maximum of {$plan->max_doctors} active doctors. You have reached this limit.",
                        'reason'  => 'plan_doctor_limit',
                        'limit'   => $plan->max_doctors,
                        'current' => $activeDoctors,
                    ], 422);
                }
            }
        }

        $token = Str::random(64);
        $invitation = Invitation::create([
            'email'           => $validated['email'],
            'token'           => $token,
            'organization_id' => $org->id,
            'role'            => $validated['role'],
            'expires_at'      => Carbon::now()->addHours(48),
        ]);

        // Send invitation email
        try {
            Mail::to($validated['email'])->send(new InvitationMail($invitation, $org));
        } catch (\Exception $e) {
            \Log::warning("InvitationMail failed for {$validated['email']}: " . $e->getMessage());
        }

        $invitation->status = 'pending';
        return response()->json(['message' => 'Invitation sent successfully.', 'invitation' => $invitation], 201);
    }

    // DELETE /org/invitations/{invitation}
    public function destroy(Invitation $invitation): JsonResponse
    {
        abort_if($invitation->organization_id !== auth()->user()->organization_id, 403, 'Not authorized.');
        $invitation->delete();
        return response()->json(['message' => 'Invitation revoked.']);
    }
}
