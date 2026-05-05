<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
#[OA\Tag(name: "Plans", description: "Subscription tiers management")]
class PlanController extends Controller
{
    #[OA\Get(path: "/api/plans", tags: ["Plans"])]
    #[OA\Response(response: 200, description: "Public list")]
    public function index(): JsonResponse
    {
        return response()->json([
            'plans' => Plan::all()
        ]);
    }

    #[OA\Post(path: "/api/plans", tags: ["Admin - Plans"], security: [["sanctum" => []]])]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["name", "price", "max_users", "max_storage_gb"],
            properties: [
                new OA\Property(property: "name", type: "string", example: "Pro Plan"),
                new OA\Property(property: "slug", type: "string", description: "Auto-generated if omitted", example: "pro-plan"),
                new OA\Property(property: "price", type: "number", format: "float", example: 99.99),
                new OA\Property(property: "max_users", type: "integer", example: 50),
                new OA\Property(property: "max_storage_gb", type: "integer", example: 100)
            ]
        )
    )]
    #[OA\Response(response: 201, description: "Plan created")]
    public function store(StorePlanRequest $request): JsonResponse
    {
        $plan = Plan::create($request->validated());

        return response()->json([
            'message' => 'Plan created successfully',
            'plan' => $plan
        ], 201);
    }

    #[OA\Get(path: "/api/plans/{plan}", tags: ["Plans"])]
    #[OA\Parameter(name: "plan", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(response: 200, description: "Plan details")]
    public function show(Plan $plan): JsonResponse
    {
        return response()->json(['plan' => $plan]);
    }

    #[OA\Put(path: "/api/plans/{plan}", tags: ["Admin - Plans"], security: [["sanctum" => []]])]
    #[OA\Parameter(name: "plan", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["name", "price", "max_users", "max_storage_gb"],
            properties: [
                new OA\Property(property: "name", type: "string", example: "Pro Plan Updated"),
                new OA\Property(property: "slug", type: "string", example: "pro-plan-updated"),
                new OA\Property(property: "price", type: "number", format: "float", example: 120.00),
                new OA\Property(property: "max_users", type: "integer", example: 75),
                new OA\Property(property: "max_storage_gb", type: "integer", example: 200)
            ]
        )
    )]
    #[OA\Response(response: 200, description: "Plan updated")]
    public function update(StorePlanRequest $request, Plan $plan): JsonResponse
    {
        $plan->update($request->validated());

        return response()->json([
            'message' => 'Plan updated successfully',
            'plan' => $plan
        ]);
    }

    #[OA\Delete(path: "/api/plans/{plan}", tags: ["Admin - Plans"], security: [["sanctum" => []]])]
    #[OA\Parameter(name: "plan", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(response: 200, description: "Plan deleted")]
    public function destroy(Request $request, Plan $plan): JsonResponse
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if any organization is currently using this plan
        if ($plan->organizations()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a plan that is currently in use by organizations.'
            ], 400);
        }

        $plan->delete();

        return response()->json(['message' => 'Plan deleted successfully']);
    }
}
