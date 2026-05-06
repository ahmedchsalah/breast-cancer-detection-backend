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
use OpenApi\Attributes as OA;

class UserManagementController extends Controller
{
    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/admin/users",
        tags: ["Admin — Users"],
        summary: "List all users platform-wide with filtering",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "role", in: "query", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "organization_id", in: "query", required: false, schema: new OA\Schema(type: "integer")),
            new OA\Parameter(name: "is_active", in: "query", required: false, schema: new OA\Schema(type: "boolean")),
            new OA\Parameter(name: "search", in: "query", required: false, schema: new OA\Schema(type: "string")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated list of users",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "data", type: "array", items: new OA\Items(ref: "#/components/schemas/UserResource")),
                        new OA\Property(property: "current_page", type: "integer"),
                        new OA\Property(property: "total", type: "integer"),
                    ]
                )
            )
        ]
    )]
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

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/admin/users/{id}",
        tags: ["Admin — Users"],
        summary: "Show a single user",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "User details", content: new OA\JsonContent(ref: "#/components/schemas/UserResource")),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(User $user): JsonResponse
    {
        return response()->json(new UserResource($user->load('organization', 'roles')));
    }

    // ============================================================
    //  STORE
    // ============================================================

    #[OA\Post(
        path: "/admin/users",
        tags: ["Admin — Users"],
        summary: "Create a user directly (admin bypass, activated immediately)",
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "email", "password", "role"],
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "email", type: "string", format: "email"),
                    new OA\Property(property: "password", type: "string", format: "password"),
                    new OA\Property(property: "role", type: "string", enum: ["admin", "instructor", "org_manager", "doctor"]),
                    new OA\Property(property: "organization_id", type: "integer", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "User created", content: new OA\JsonContent(ref: "#/components/schemas/UserResource")),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['password']  = Hash::make($validated['password']);
        $validated['is_active'] = true;

        $user = User::create($validated);
        $user->assignRole($validated['role']);

        return response()->json(new UserResource($user->load('organization', 'roles')), 201);
    }

    // ============================================================
    //  UPDATE
    // ============================================================

    #[OA\Put(
        path: "/admin/users/{id}",
        tags: ["Admin — Users"],
        summary: "Update user details",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "email", type: "string", format: "email"),
                    new OA\Property(property: "password", type: "string", format: "password", nullable: true),
                    new OA\Property(property: "role", type: "string", enum: ["admin", "instructor", "org_manager", "doctor"]),
                    new OA\Property(property: "organization_id", type: "integer", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "User updated", content: new OA\JsonContent(ref: "#/components/schemas/UserResource")),
            new OA\Response(response: 404, description: "Not found"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
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

    // ============================================================
    //  DESTROY
    // ============================================================

    #[OA\Delete(
        path: "/admin/users/{id}",
        tags: ["Admin — Users"],
        summary: "Delete a user",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "User deleted"),
            new OA\Response(response: 422, description: "Cannot delete your own account"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function destroy(User $user): JsonResponse
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully.']);
    }

    // ============================================================
    //  ACTIVATE
    // ============================================================

    #[OA\Post(
        path: "/admin/users/{id}/activate",
        tags: ["Admin — Users"],
        summary: "Activate a user account",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "User activated"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function activate(User $user): JsonResponse
    {
        $user->update(['is_active' => true]);

        return response()->json(['message' => "User '{$user->name}' has been activated.", 'user' => new UserResource($user)]);
    }

    // ============================================================
    //  DEACTIVATE
    // ============================================================

    #[OA\Post(
        path: "/admin/users/{id}/deactivate",
        tags: ["Admin — Users"],
        summary: "Deactivate a user account (soft-lock)",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "User deactivated"),
            new OA\Response(response: 422, description: "Cannot deactivate your own account"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
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
