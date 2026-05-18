<?php

namespace App\Http\Controllers\Api\OrgManager;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Mail\DoctorActivatedMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use OpenApi\Attributes as OA;

class MemberController extends Controller
{
    private function orgId(): int
    {
        return auth()->user()->organization_id;
    }

    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/org-manager/members",
        tags: ["OrgManager — Members"],
        summary: "List all members of this organization",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "role", in: "query", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "is_active", in: "query", required: false, schema: new OA\Schema(type: "boolean")),
            new OA\Parameter(name: "search", in: "query", required: false, schema: new OA\Schema(type: "string")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated list of members",
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

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/org-manager/members/{id}",
        tags: ["OrgManager — Members"],
        summary: "Show a single member",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Member details",
                content: new OA\JsonContent(ref: "#/components/schemas/UserResource")
            ),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(User $user): JsonResponse
    {
        $this->ensureSameOrg($user);

        return response()->json(new UserResource($user->load('roles')));
    }

    // ============================================================
    //  APPROVE
    // ============================================================

    #[OA\Post(
        path: "/org-manager/members/{id}/approve",
        tags: ["OrgManager — Members"],
        summary: "Approve (activate) a pending doctor in this organization",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Member approved"),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 422, description: "Member already active"),
        ]
    )]
    public function approve(User $user): JsonResponse
    {
        $this->ensureSameOrg($user);

        if ($user->is_active) {
            return response()->json(['message' => 'This member is already active.'], 422);
        }

        $user->update(['is_active' => true]);

        // Send activation email to the doctor
        try {
            $organization = $user->organization;
            if ($organization) {
                Mail::to($user->email)->send(new DoctorActivatedMail($user, $organization));
            }
        } catch (\Exception $e) {
            \Log::warning("DoctorActivatedMail failed for {$user->email}: " . $e->getMessage());
        }

        return response()->json(['message' => "Dr. {$user->name} has been approved and can now log in."]);
    }

    // ============================================================
    //  DEACTIVATE
    // ============================================================

    #[OA\Post(
        path: "/org-manager/members/{id}/deactivate",
        tags: ["OrgManager — Members"],
        summary: "Deactivate a member (suspend their access to this org)",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Member deactivated"),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 422, description: "Cannot deactivate yourself"),
        ]
    )]
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

    // ============================================================
    //  DESTROY
    // ============================================================

    #[OA\Delete(
        path: "/org-manager/members/{id}",
        tags: ["OrgManager — Members"],
        summary: "Remove a member from the organization",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Member removed"),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 422, description: "Cannot remove yourself"),
        ]
    )]
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
