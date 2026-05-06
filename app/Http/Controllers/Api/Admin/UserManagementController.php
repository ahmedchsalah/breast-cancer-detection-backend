<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Http\Requests\Api\User\StoreUserRequest;
use App\Http\Requests\Api\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserManagementController extends Controller
{
    /**
     * List all users platform-wide with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'role'            => 'nullable|string',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'is_active'       => 'nullable|boolean',
            'search'          => 'nullable|string|max:100',
        ]);

        $query = User::with('organization:id,name', 'roles')
            ->withCount('examinations', 'reports');

        if ($request->filled('role')) {
            $query->role($request->role); // Spatie helper
        }
        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
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
     * Show a single user.
     */
    public function show(User $user): JsonResponse
    {
        return response()->json(new UserResource($user->load('organization', 'roles')));
    }

    /**
     * Create a user directly (admin bypass, activated immediately).
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['password']  = Hash::make($validated['password']);
        $validated['is_active'] = true;

        $user = User::create($validated);
        $user->assignRole($validated['role']);

        return response()->json(new UserResource($user->load('organization', 'roles')), 201);
    }

    /**
     * Update user details.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        if (!empty($validated['role'])) {
            $user->syncRoles([$validated['role']]);
        }

        return response()->json(new UserResource($user->fresh()->load('organization', 'roles')));
    }

    /**
     * Delete a user.
     */
    public function destroy(User $user): JsonResponse
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully.']);
    }

    /**
     * Activate a user account.
     */
    public function activate(User $user): JsonResponse
    {
        $user->update(['is_active' => true]);

        return response()->json(['message' => "User '{$user->name}' has been activated.", 'user' => new UserResource($user)]);
    }

    /**
     * Deactivate a user account (soft-lock).
     */
    public function deactivate(User $user): JsonResponse
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        $user->update(['is_active' => false]);
        // Revoke all tokens so they are kicked out immediately
        $user->tokens()->delete();

        return response()->json(['message' => "User '{$user->name}' has been deactivated."]);
    }
}
