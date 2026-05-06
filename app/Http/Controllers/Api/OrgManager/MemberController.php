<?php

namespace App\Http\Controllers\Api\OrgManager;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class MemberController extends Controller
{
    private function orgId(): int
    {
        return auth()->user()->organization_id;
    }

    /**
     * List all members of this organization.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'role'      => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'search'    => 'nullable|string|max:100',
        ]);

        $query = User::where('organization_id', $this->orgId())
            ->with('roles')
            ->withCount('examinations', 'reports');

        if ($request->filled('role')) {
            $query->role($request->role);
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        return response()->json(UserResource::collection($query->orderByDesc('created_at')->paginate(15))->resource);
    }

    /**
     * Show a single member.
     */
    public function show(User $user): JsonResponse
    {
        $this->ensureSameOrg($user);

        return response()->json(new UserResource($user->load('roles')));
    }

    /**
     * Approve (activate) a pending doctor in this organization.
     */
    public function approve(User $user): JsonResponse
    {
        $this->ensureSameOrg($user);

        if ($user->is_active) {
            return response()->json(['message' => 'This member is already active.'], 422);
        }

        $user->update(['is_active' => true]);

        return response()->json(['message' => "Dr. {$user->name} has been approved and can now log in."]);
    }

    /**
     * Deactivate a member (suspend their access to this org).
     */
    public function deactivate(User $user): JsonResponse
    {
        $this->ensureSameOrg($user);

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'You cannot deactivate yourself.'], 422);
        }

        $user->update(['is_active' => false]);
        $user->tokens()->delete(); // Force logout

        return response()->json(['message' => "Dr. {$user->name} has been deactivated."]);
    }

    /**
     * Remove a member from the organization.
     */
    public function destroy(User $user): JsonResponse
    {
        $this->ensureSameOrg($user);

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'You cannot remove yourself.'], 422);
        }

        $user->update(['organization_id' => null, 'is_active' => false]);
        $user->tokens()->delete();

        return response()->json(['message' => 'Member removed from organization.']);
    }

    private function ensureSameOrg(User $user): void
    {
        abort_if($user->organization_id !== $this->orgId(), 403, 'This member does not belong to your organization.');
    }
}
