<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Prediction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AdminPredictionController extends Controller
{
    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/admin/predictions",
        tags: ["Admin — Predictions"],
        summary: "List all predictions platform-wide with filtering",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "status", in: "query", required: false, schema: new OA\Schema(type: "string", enum: ["pending", "processing", "completed", "failed"])),
            new OA\Parameter(name: "organization_id", in: "query", required: false, schema: new OA\Schema(type: "integer")),
            new OA\Parameter(name: "ai_model_id", in: "query", required: false, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated list of predictions",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "data", type: "array", items: new OA\Items(ref: "#/components/schemas/PredictionObject")),
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
            'status'          => 'nullable|in:pending,processing,completed,failed',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'ai_model_id'     => 'nullable|integer|exists:ai_models,id',
        ]);

        $query = Prediction::with([
            'patient:id,patient_identifier',
            'examination:id',
            'aiModel:id,name,version',
            'organization:id,name',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }
        if ($request->filled('ai_model_id')) {
            $query->where('ai_model_id', $request->ai_model_id);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(15));
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/admin/predictions/{id}",
        tags: ["Admin — Predictions"],
        summary: "Show a single prediction with full relationship loading",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Prediction details",
                content: new OA\JsonContent(ref: "#/components/schemas/PredictionObject")
            ),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(Prediction $prediction): JsonResponse
    {
        $prediction->load([
            'patient',
            'examination',
            'aiModel',
            'organization',
            'wsiUpload',
            'xaiResult',
            'report',
        ]);

        return response()->json($prediction);
    }
}
