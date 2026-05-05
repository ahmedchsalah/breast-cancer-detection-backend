<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse; // <-- Added missing import

use OpenApi\Attributes as OA;
#[OA\Tag(name: "Organizations", description: "Management of clinics and hospitals")]
class OrganizationController extends Controller
{
    #[OA\Get(path: "/api/organizations", tags: ["Admin - Organizations"], security: [["sanctum" => []]])]
    #[OA\Response(response: 200, description: "List all (Admin)")]
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

        return response()->json([
            'organizations' => Organization::with('plan')->get()
        ]);
    }

    #[OA\Post(path: "/api/organizations", tags: ["Admin - Organizations"], security: [["sanctum" => []]])]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["name", "plan_id", "type", "contact_email"],
            properties: [
                new OA\Property(property: "name", type: "string", example: "City Clinic"),
                new OA\Property(property: "plan_id", type: "integer", example: 1),
                new OA\Property(property: "type", type: "string", example: "clinic"),
                new OA\Property(property: "contact_email", type: "string", format: "email", example: "admin@cityclinic.com"),
                new OA\Property(property: "address", type: "string", example: "456 Wellness Ave")
            ]
        )
    )]
    #[OA\Response(response: 201, description: "Created")]
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // System generated values
        $validated['code'] = strtoupper(Str::random(8));

        // If Admin is creating it, we can default to active,
        // otherwise let the migration default ('pending') handle it.
        $organization = Organization::create($validated);

        return response()->json([
            'message' => 'Organization created successfully',
            'organization' => $organization
        ], 201);
    }

    #[OA\Get(path: "/api/organization", tags: ["My Organization"], security: [["sanctum" => []]])]
    #[OA\Response(response: 200, description: "Show my org")]
    public function show(Request $request, ?Organization $organization = null): JsonResponse
    {
        // If no ID is passed, assume the user is asking for "my organization"
        $org = ($organization && $organization->exists) ? $organization : $request->user()->organization;

        if (!$org) {
            return response()->json(['message' => 'Organization not found'], 404);
        }

        // Authorization check
        if (!$request->user()->hasRole('admin') && $request->user()->organization_id !== $org->id) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

        return response()->json([
            'organization' => $org->load('plan')
        ]);
    }

    #[OA\Put(path: "/api/organization", tags: ["My Organization"], security: [["sanctum" => []]])]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "name", type: "string", example: "Updated Clinic Name"),
                new OA\Property(property: "type", type: "string", example: "hospital"),
                new OA\Property(property: "address", type: "string", example: "Updated Address"),
                new OA\Property(property: "contact_email", type: "string", format: "email"),
                new OA\Property(property: "plan_id", type: "integer", description: "Admin only"),
                new OA\Property(property: "subscription_status", type: "string", enum: ["trial", "active", "past_due", "canceled"], description: "Admin only")
            ]
        )
    )]
    #[OA\Response(response: 200, description: "Update my org")]
    public function update(UpdateOrganizationRequest $request, ?Organization $organization = null): JsonResponse
    {
        $org = ($organization && $organization->exists) ? $organization : $request->user()->organization;

        // Ensure user has permission to update this specific org
        if (!$request->user()->hasRole('admin') && $request->user()->organization_id !== $org->id) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

        $org->update($request->validated());

        return response()->json([
            'message' => __('messages.org_updated'),
            'organization' => $org->fresh('plan')
        ]);
    }

    #[OA\Post(path: "/api/organizations/{organization}/approve", tags: ["Admin - Organizations"], security: [["sanctum" => []]])]
    #[OA\Parameter(name: "organization", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(response: 200, description: "Organization approved")]
    public function approve(Request $request, Organization $organization): JsonResponse
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

        // 1. Activate the Organization
        $organization->update(['status' => Organization::STATUS_ACTIVE]);

        // 2. Activate the Organization Manager's account so they can log in
        User::where('organization_id', $organization->id)
            ->role('org_manager')
            ->update(['is_active' => true]);

        return response()->json([
            'message' => 'Organization approved successfully.'
        ]);
    }

    #[OA\Delete(path: "/api/organizations/{organization}", tags: ["Admin - Organizations"], security: [["sanctum" => []]])]
    #[OA\Parameter(name: "organization", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(response: 200, description: "Deleted")]
    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

        $organization->delete();

        return response()->json([
            'message' => 'Organization deleted successfully'
        ]);
    }
}
