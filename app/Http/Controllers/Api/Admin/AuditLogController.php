<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AuditLogController extends Controller
{
    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/admin/audit-logs",
        tags: ["Admin — Audit Logs"],
        summary: "List audit logs with filtering",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "user_id",         in: "query", required: false, schema: new OA\Schema(type: "integer")),
            new OA\Parameter(name: "organization_id", in: "query", required: false, schema: new OA\Schema(type: "integer")),
            new OA\Parameter(name: "action",          in: "query", required: false, schema: new OA\Schema(type: "string", example: "prediction.created")),
            new OA\Parameter(name: "auditable_type",  in: "query", required: false, schema: new OA\Schema(type: "string", example: "App\\Models\\Prediction")),
            new OA\Parameter(name: "from",            in: "query", required: false, schema: new OA\Schema(type: "string", format: "date", example: "2026-01-01")),
            new OA\Parameter(name: "to",              in: "query", required: false, schema: new OA\Schema(type: "string", format: "date", example: "2026-12-31")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated audit logs",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "data",         type: "array", items: new OA\Items(type: "object")),
                        new OA\Property(property: "current_page", type: "integer"),
                        new OA\Property(property: "total",        type: "integer"),
                    ]
                )
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'         => 'nullable|integer|exists:users,id',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'action'          => 'nullable|string|max:100',
            'auditable_type'  => 'nullable|string|max:100',
            'from'            => 'nullable|date',
            'to'              => 'nullable|date|after_or_equal:from',
        ]);

        $query = AuditLog::with('user:id,name,email', 'organization:id,name')
            ->orderByDesc('created_at');

        if ($request->filled('user_id'))         $query->where('user_id', $request->user_id);
        if ($request->filled('organization_id')) $query->where('organization_id', $request->organization_id);
        if ($request->filled('action'))          $query->where('action', 'like', '%' . $request->action . '%');
        if ($request->filled('auditable_type'))  $query->where('auditable_type', $request->auditable_type);
        if ($request->filled('from'))            $query->where('created_at', '>=', $request->from);
        if ($request->filled('to'))              $query->where('created_at', '<=', $request->to . ' 23:59:59');

        return response()->json($query->paginate(25));
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/admin/audit-logs/{id}",
        tags: ["Admin — Audit Logs"],
        summary: "Show details of a single audit log entry",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Audit log entry"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(AuditLog $auditLog): JsonResponse
    {
        return response()->json($auditLog->load('user:id,name,email', 'organization:id,name'));
    }
}
