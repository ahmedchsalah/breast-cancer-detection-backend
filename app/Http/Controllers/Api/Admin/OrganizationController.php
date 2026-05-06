<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Http\Requests\Api\Organization\StoreOrganizationRequest;
use App\Http\Requests\Api\Organization\UpdateOrganizationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * List all organizations with filtering and pagination.
     */
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

    /**
     * Show a single organization with full details.
     */
    public function show(Organization $organization): JsonResponse
    {
        $organization->load(['plan', 'subscriptions' => fn($q) => $q->latest()->limit(1)])
            ->loadCount('users', 'patients', 'examinations', 'predictions');

        return response()->json($organization);
    }

    /**
     * Create a new organization (admin can do this directly).
     */
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $org = Organization::create($request->validated());

        return response()->json($org, 201);
    }

    /**
     * Update organization details.
     */
    public function update(UpdateOrganizationRequest $request, Organization $organization): JsonResponse
    {
        $organization->update($request->validated());

        return response()->json($organization->fresh());
    }

    /**
     * Delete an organization (cascades on DB level).
     */
    public function destroy(Organization $organization): JsonResponse
    {
        $organization->delete();

        return response()->json(['message' => 'Organization deleted successfully.']);
    }

    /**
     * Approve a pending organization and activate it.
     */
    public function approve(Organization $organization): JsonResponse
    {
        if ($organization->status !== Organization::STATUS_PENDING) {
            return response()->json(['message' => 'Organization is not in a pending state.'], 422);
        }

        $organization->update(['status' => Organization::STATUS_ACTIVE]);

        return response()->json(['message' => 'Organization approved and activated.', 'organization' => $organization]);
    }

    /**
     * Reject a pending organization.
     */
    public function reject(Organization $organization): JsonResponse
    {
        $organization->update(['status' => Organization::STATUS_REJECTED]);

        return response()->json(['message' => 'Organization rejected.', 'organization' => $organization]);
    }

    /**
     * Suspend an active organization.
     */
    public function suspend(Organization $organization): JsonResponse
    {
        $organization->update(['status' => Organization::STATUS_SUSPENDED]);

        return response()->json(['message' => 'Organization suspended.', 'organization' => $organization]);
    }
}
