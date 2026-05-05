<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse; // <-- Added missing import
use App\Models\User; // <-- Added missing import
use Illuminate\Support\Facades\Storage; // <-- Added missing import
use App\Http\Requests\UpdateProfileRequest; // Make sure this exists
use App\Http\Requests\UpdateAvatarRequest; // Make sure this exists
#[OA\Tag(name: "Users", description: "User and staff management")]
class UserController extends Controller
{
    #[OA\Get(path: "/api/users", tags: ["Users"], security: [["sanctum" => []]])]
    #[OA\Response(response: 200, description: "List users")]
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user();
        $query = User::with(['roles', 'organization']);

        if (!$admin->hasRole('admin')) {
            $query->where('organization_id', $admin->organization_id);
        }

        return response()->json([
            'users' => UserResource::collection($query->get())
        ]);
    }
    #[OA\Post(path: "/api/users", tags: ["Users"], security: [["sanctum" => []]])]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["name", "email", "password", "role"],
            properties: [
                new OA\Property(property: "name", type: "string", example: "Dr. Smith"),
                new OA\Property(property: "email", type: "string", format: "email", example: "smith@clinic.com"),
                new OA\Property(property: "password", type: "string", format: "password", example: "secret123"),
                new OA\Property(property: "role", type: "string", enum: ["doctor", "org_manager"], example: "doctor"),
                new OA\Property(property: "organization_id", type: "integer", description: "Required only if caller is Super Admin", example: 1)
            ]
        )
    )]
    #[OA\Response(response: 201, description: "User created")]
    public function store(StoreUserRequest $request): JsonResponse
    {
        $admin = $request->user();
        $validated = $request->validated();

        // If Org Manager, force their own org ID. If Admin, take from request.
        $orgId = $admin->hasRole('admin') ? $validated['organization_id'] : $admin->organization_id;

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'organization_id' => $orgId,
            'is_active' => true,
        ]);

        $user->assignRole($validated['role']);

        return response()->json([
            'message' => 'User created successfully',
            'user' => new UserResource($user->load('roles'))
        ], 201);
    }
    #[OA\Get(path: "/api/users/{user}", tags: ["Users"], security: [["sanctum" => []]])]
    #[OA\Parameter(name: "user", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(response: 200, description: "User details")]
    public function show(Request $request, User $user): JsonResponse
    {
        // Still need a quick manual check here unless you make a 'ShowUserRequest'
        if (!$request->user()->hasRole('admin') && $user->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        return response()->json([
            'user' => new UserResource($user->load(['roles', 'organization']))
        ]);
    }
    #[OA\Put(path: "/api/users/{user}", tags: ["Users"], security: [["sanctum" => []]])]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "name", type: "string", example: "Updated Name"),
                new OA\Property(property: "email", type: "string", format: "email"),
                new OA\Property(property: "is_active", type: "boolean", example: true),
                new OA\Property(property: "role", type: "string", enum: ["doctor", "org_manager"]),
                new OA\Property(property: "password", type: "string", format: "password", description: "Leave blank to keep unchanged")
            ]
        )
    )]
    #[OA\Response(response: 200, description: "User updated")]
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        if (isset($validated['role'])) {
            $user->syncRoles([$validated['role']]);
        }

        return response()->json([
            'message' => 'User updated successfully',
            'user' => new UserResource($user->fresh('roles'))
        ]);
    }
    #[OA\Delete(path: "/api/users/{user}", tags: ["Users"], security: [["sanctum" => []]])]
    #[OA\Parameter(name: "user", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(response: 200, description: "User deleted")]
    public function destroy(Request $request, User $user): JsonResponse
    {
        $admin = $request->user();

        // Authorization check
        if (!$admin->hasRole('admin') && $user->organization_id !== $admin->organization_id) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        if ($user->id === $admin->id) {
            return response()->json(['message' => 'Cannot delete yourself'], 400);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }
}
