<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AdminPaymentController extends Controller
{
    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/admin/payments",
        tags: ["Admin — Payments"],
        summary: "List all payments platform-wide with filtering",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "status", in: "query", required: false, schema: new OA\Schema(type: "string", enum: ["pending", "completed", "failed", "refunded"])),
            new OA\Parameter(name: "organization_id", in: "query", required: false, schema: new OA\Schema(type: "integer")),
            new OA\Parameter(name: "page", in: "query", required: false, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated list of payments",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "data", type: "array", items: new OA\Items(ref: "#/components/schemas/PaymentObject")),
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
            'status'          => 'nullable|string|in:pending,completed,failed,refunded',
            'organization_id' => 'nullable|integer|exists:organizations,id',
        ]);

        $query = Payment::with(
            'organization:id,name',
            'plan:id,name',
            'subscription:id'
        );

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
        path: "/admin/payments/{id}",
        tags: ["Admin — Payments"],
        summary: "Show a single payment with full relationship details",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Payment details", content: new OA\JsonContent(ref: "#/components/schemas/PaymentObject")),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(Payment $payment): JsonResponse
    {
        return response()->json(
            $payment->load('organization', 'plan', 'subscription')
        );
    }
}
