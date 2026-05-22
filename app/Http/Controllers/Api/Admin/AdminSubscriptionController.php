<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AdminSubscriptionController extends Controller
{
    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/admin/subscriptions",
        tags: ["Admin — Subscriptions"],
        summary: "List all subscriptions platform-wide with filtering",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "status", in: "query", required: false, schema: new OA\Schema(type: "string", enum: ["active", "trialing", "expired", "cancelled"])),
            new OA\Parameter(name: "organization_id", in: "query", required: false, schema: new OA\Schema(type: "integer")),
            new OA\Parameter(name: "page", in: "query", required: false, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated list of subscriptions",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object")),
                        new OA\Property(property: "current_page", type: "integer"),
                        new OA\Property(property: "total", type: "integer"),
                    ]
                )
            ),
            new OA\Response(response: 403, description: "Forbidden — admin role required"),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'          => 'nullable|string|in:active,trialing,expired,cancelled',
            'organization_id' => 'nullable|integer|exists:organizations,id',
        ]);

        $query = Subscription::with([
            'organization:id,name,type',
            'plan:id,name,price,max_doctors',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        return response()->json(
            $query->orderByDesc('created_at')->paginate(15)
        );
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/admin/subscriptions/{id}",
        tags: ["Admin — Subscriptions"],
        summary: "Show a single subscription with full relationship loading",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Subscription details"),
            new OA\Response(response: 403, description: "Forbidden — admin role required"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(Subscription $subscription): JsonResponse
    {
        return response()->json(
            $subscription->load('organization', 'plan')
        );
    }
}
