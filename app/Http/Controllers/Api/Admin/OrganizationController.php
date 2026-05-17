<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Http\Requests\Api\Organization\StoreOrganizationRequest;
use App\Http\Requests\Api\Organization\UpdateOrganizationRequest;
use App\Mail\OrgApprovedMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "OrganizationObject",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer"),
        new OA\Property(property: "name", type: "string"),
        new OA\Property(property: "type", type: "string"),
        new OA\Property(property: "status", type: "string"),
        new OA\Property(property: "contact_email", type: "string"),
        new OA\Property(property: "address", type: "string"),
        new OA\Property(property: "latitude", type: "number", format: "float", nullable: true),
        new OA\Property(property: "longitude", type: "number", format: "float", nullable: true),
        new OA\Property(property: "plan_id", type: "integer", nullable: true),
        new OA\Property(property: "subscription_status", type: "string"),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
    ]
)]
class OrganizationController extends Controller
{
    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/admin/organizations",
        tags: ["Admin — Organizations"],
        summary: "List all organizations with filtering and pagination",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "status", in: "query", required: false, schema: new OA\Schema(type: "string", enum: ["pending", "active", "rejected", "suspended"])),
            new OA\Parameter(name: "type", in: "query", required: false, schema: new OA\Schema(type: "string", enum: ["clinic", "hospital", "laboratory", "radiology_center"])),
            new OA\Parameter(name: "search", in: "query", required: false, schema: new OA\Schema(type: "string")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated list of organizations",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "data", type: "array", items: new OA\Items(ref: "#/components/schemas/OrganizationObject")),
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
            'status' => 'nullable|in:pending,active,rejected,suspended',
            'type'   => 'nullable|in:clinic,hospital,laboratory,radiology_center',
            'search' => 'nullable|string|max:100',
        ]);

        $query = Organization::with(['plan:id,name', 'users' => fn($q) => $q->select('id', 'organization_id')])
            ->withCount('users', 'patients', 'examinations', 'predictions');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        return response()->json($query->orderByDesc('created_at')->paginate(15));
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/admin/organizations/{id}",
        tags: ["Admin — Organizations"],
        summary: "Show a single organization with full details",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Organization details",
                content: new OA\JsonContent(ref: "#/components/schemas/OrganizationObject")
            ),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(Organization $organization): JsonResponse
    {
        $organization->load(['plan', 'subscriptions' => fn($q) => $q->latest()->limit(1)])
            ->loadCount('users', 'patients', 'examinations', 'predictions');

        return response()->json($organization);
    }

    // ============================================================
    //  STORE
    // ============================================================

    #[OA\Post(
        path: "/admin/organizations",
        tags: ["Admin — Organizations"],
        summary: "Create a new organization (admin can do this directly)",
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "type", "contact_email"],
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "type", type: "string", enum: ["clinic", "hospital", "laboratory", "radiology_center"]),
                    new OA\Property(property: "contact_email", type: "string", format: "email"),
                    new OA\Property(property: "address", type: "string", nullable: true),
                    new OA\Property(property: "latitude", type: "number", format: "float", nullable: true),
                    new OA\Property(property: "longitude", type: "number", format: "float", nullable: true),
                    new OA\Property(property: "plan_id", type: "integer", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Organization created", content: new OA\JsonContent(ref: "#/components/schemas/OrganizationObject")),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $org = Organization::create($request->validated());

        return response()->json($org, 201);
    }

    // ============================================================
    //  UPDATE
    // ============================================================

    #[OA\Put(
        path: "/admin/organizations/{id}",
        tags: ["Admin — Organizations"],
        summary: "Update organization details",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "type", type: "string", enum: ["clinic", "hospital", "laboratory", "radiology_center"]),
                    new OA\Property(property: "contact_email", type: "string", format: "email"),
                    new OA\Property(property: "address", type: "string", nullable: true),
                    new OA\Property(property: "latitude", type: "number", format: "float", nullable: true),
                    new OA\Property(property: "longitude", type: "number", format: "float", nullable: true),
                    new OA\Property(property: "plan_id", type: "integer", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Organization updated", content: new OA\JsonContent(ref: "#/components/schemas/OrganizationObject")),
            new OA\Response(response: 404, description: "Not found"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function update(UpdateOrganizationRequest $request, Organization $organization): JsonResponse
    {
        $organization->update($request->validated());

        return response()->json($organization->fresh());
    }

    // ============================================================
    //  DESTROY
    // ============================================================

    #[OA\Delete(
        path: "/admin/organizations/{id}",
        tags: ["Admin — Organizations"],
        summary: "Delete an organization (cascades on DB level)",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Organization deleted"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function destroy(Organization $organization): JsonResponse
    {
        $organization->delete();

        return response()->json(['message' => 'Organization deleted successfully.']);
    }

    // ============================================================
    //  APPROVE
    // ============================================================

    #[OA\Post(
        path: "/admin/organizations/{id}/approve",
        tags: ["Admin — Organizations"],
        summary: "Approve a pending organization and activate it",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Organization approved"),
            new OA\Response(response: 422, description: "Organization is not pending"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function approve(Organization $organization): JsonResponse
    {
        if ($organization->status !== Organization::STATUS_PENDING) {
            return response()->json(['message' => 'Organization is not in a pending state.'], 422);
        }

        $organization->update(['status' => Organization::STATUS_ACTIVE]);

        // Auto-activate the org manager account(s) and send approval email
        $managers = User::where('organization_id', $organization->id)
            ->role('org_manager')
            ->get();

        foreach ($managers as $manager) {
            if (!$manager->is_active) {
                $manager->update(['is_active' => true]);
            }

            // Send approval notification email
            try {
                Mail::to($manager->email)->send(new OrgApprovedMail($manager, $organization));
            } catch (\Exception $e) {
                \Log::warning("OrgApprovedMail failed for {$manager->email}: " . $e->getMessage());
            }
        }

        return response()->json([
            'message'      => 'Organization approved, manager accounts activated, and notification emails sent.',
            'organization' => $organization,
            'activated'    => $managers->count(),
        ]);
    }

    // ============================================================
    //  REJECT
    // ============================================================

    #[OA\Post(
        path: "/admin/organizations/{id}/reject",
        tags: ["Admin — Organizations"],
        summary: "Reject a pending organization",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Organization rejected"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function reject(Organization $organization): JsonResponse
    {
        $organization->update(['status' => Organization::STATUS_REJECTED]);

        return response()->json(['message' => 'Organization rejected.', 'organization' => $organization]);
    }

    // ============================================================
    //  SUSPEND
    // ============================================================

    #[OA\Post(
        path: "/admin/organizations/{id}/suspend",
        tags: ["Admin — Organizations"],
        summary: "Suspend an active organization",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Organization suspended"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function suspend(Organization $organization): JsonResponse
    {
        $organization->update(['status' => Organization::STATUS_SUSPENDED]);

        return response()->json(['message' => 'Organization suspended.', 'organization' => $organization]);
    }
}
