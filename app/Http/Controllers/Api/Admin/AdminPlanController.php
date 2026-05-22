<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "PlanObject",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer"),
        new OA\Property(property: "name", type: "string"),
        new OA\Property(property: "slug", type: "string"),
        new OA\Property(property: "description", type: "string", nullable: true),
        new OA\Property(property: "price", type: "number", format: "float"),
        new OA\Property(property: "max_doctors", type: "integer", nullable: true),
        new OA\Property(property: "max_predictions_per_month", type: "integer", nullable: true),
        new OA\Property(property: "fl_contribution_allowed", type: "boolean"),
        new OA\Property(property: "instructor_allowed", type: "boolean"),
        new OA\Property(property: "is_active", type: "boolean"),
        new OA\Property(property: "subscriptions_count", type: "integer"),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
        new OA\Property(property: "updated_at", type: "string", format: "date-time"),
    ]
)]
class AdminPlanController extends Controller
{
    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/admin/plans",
        tags: ["Admin — Plans"],
        summary: "List all plans with subscriptions count",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "List of plans",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(ref: "#/components/schemas/PlanObject")
                )
            )
        ]
    )]
    public function index(): JsonResponse
    {
        $plans = Plan::withCount('subscriptions')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($plans);
    }

    // ============================================================
    //  STORE
    // ============================================================

    #[OA\Post(
        path: "/admin/plans",
        tags: ["Admin — Plans"],
        summary: "Create a new subscription plan",
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "slug", "price"],
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "slug", type: "string"),
                    new OA\Property(property: "description", type: "string", nullable: true),
                    new OA\Property(property: "price", type: "number", format: "float"),
                    new OA\Property(property: "max_doctors", type: "integer", nullable: true),
                    new OA\Property(property: "max_predictions_per_month", type: "integer", nullable: true),
                    new OA\Property(property: "fl_contribution_allowed", type: "boolean"),
                    new OA\Property(property: "instructor_allowed", type: "boolean"),
                    new OA\Property(property: "is_active", type: "boolean"),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Plan created", content: new OA\JsonContent(ref: "#/components/schemas/PlanObject")),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                      => 'required|string|max:255',
            'slug'                      => 'required|string|max:255|unique:plans,slug',
            'description'               => 'nullable|string',
            'price'                     => 'required|numeric|min:0',
            'max_doctors'               => 'nullable|integer',
            'max_predictions_per_month' => 'nullable|integer',
            'fl_contribution_allowed'   => 'boolean',
            'instructor_allowed'        => 'boolean',
            'is_active'                 => 'boolean',
        ]);

        $plan = Plan::create($validated);

        return response()->json($plan->loadCount('subscriptions'), 201);
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/admin/plans/{id}",
        tags: ["Admin — Plans"],
        summary: "Show a single plan",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Plan details", content: new OA\JsonContent(ref: "#/components/schemas/PlanObject")),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(Plan $plan): JsonResponse
    {
        return response()->json($plan->loadCount('subscriptions'));
    }

    // ============================================================
    //  UPDATE
    // ============================================================

    #[OA\Put(
        path: "/admin/plans/{id}",
        tags: ["Admin — Plans"],
        summary: "Update a plan's details",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "slug", type: "string"),
                    new OA\Property(property: "description", type: "string", nullable: true),
                    new OA\Property(property: "price", type: "number", format: "float"),
                    new OA\Property(property: "max_doctors", type: "integer", nullable: true),
                    new OA\Property(property: "max_predictions_per_month", type: "integer", nullable: true),
                    new OA\Property(property: "fl_contribution_allowed", type: "boolean"),
                    new OA\Property(property: "instructor_allowed", type: "boolean"),
                    new OA\Property(property: "is_active", type: "boolean"),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Plan updated", content: new OA\JsonContent(ref: "#/components/schemas/PlanObject")),
            new OA\Response(response: 404, description: "Not found"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function update(Request $request, Plan $plan): JsonResponse
    {
        $validated = $request->validate([
            'name'                      => 'required|string|max:255',
            'slug'                      => ['required', 'string', 'max:255', Rule::unique('plans', 'slug')->ignore($plan->id)],
            'description'               => 'nullable|string',
            'price'                     => 'required|numeric|min:0',
            'max_doctors'               => 'nullable|integer',
            'max_predictions_per_month' => 'nullable|integer',
            'fl_contribution_allowed'   => 'boolean',
            'instructor_allowed'        => 'boolean',
            'is_active'                 => 'boolean',
        ]);

        $plan->update($validated);

        return response()->json($plan->fresh()->loadCount('subscriptions'));
    }

    // ============================================================
    //  DESTROY
    // ============================================================

    #[OA\Delete(
        path: "/admin/plans/{id}",
        tags: ["Admin — Plans"],
        summary: "Delete a plan (blocked if it has active subscriptions)",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Plan deleted"),
            new OA\Response(response: 404, description: "Not found"),
            new OA\Response(response: 422, description: "Plan has active subscriptions"),
        ]
    )]
    public function destroy(Plan $plan): JsonResponse
    {
        $activeSubscriptions = $plan->subscriptions()
            ->where('status', 'active')
            ->count();

        if ($activeSubscriptions > 0) {
            return response()->json(
                ['message' => 'Cannot delete a plan with active subscriptions.'],
                422
            );
        }

        $plan->delete();

        return response()->json(['message' => 'Plan deleted successfully.']);
    }

    // ============================================================
    //  ACTIVATE
    // ============================================================

    #[OA\Post(
        path: "/admin/plans/{id}/activate",
        tags: ["Admin — Plans"],
        summary: "Activate a plan (sets is_active = true)",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Plan activated", content: new OA\JsonContent(ref: "#/components/schemas/PlanObject")),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function activate(Plan $plan): JsonResponse
    {
        $plan->update(['is_active' => true]);

        return response()->json([
            'message' => 'Plan activated.',
            'plan'    => $plan->fresh()->loadCount('subscriptions'),
        ]);
    }

    // ============================================================
    //  DEACTIVATE
    // ============================================================

    #[OA\Post(
        path: "/admin/plans/{id}/deactivate",
        tags: ["Admin — Plans"],
        summary: "Deactivate a plan (sets is_active = false)",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Plan deactivated", content: new OA\JsonContent(ref: "#/components/schemas/PlanObject")),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function deactivate(Plan $plan): JsonResponse
    {
        $plan->update(['is_active' => false]);

        return response()->json([
            'message' => 'Plan deactivated.',
            'plan'    => $plan->fresh()->loadCount('subscriptions'),
        ]);
    }
}
