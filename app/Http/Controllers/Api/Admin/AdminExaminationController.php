<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Examination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AdminExaminationController extends Controller
{
    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/admin/examinations",
        tags: ["Admin — Examinations"],
        summary: "List all examinations platform-wide with filtering",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "status", in: "query", required: false, schema: new OA\Schema(type: "string", enum: ["draft", "submitted", "predicted", "concluded"])),
            new OA\Parameter(name: "organization_id", in: "query", required: false, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated list of examinations",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "data", type: "array", items: new OA\Items(ref: "#/components/schemas/ExaminationObject")),
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
            'status'          => 'nullable|in:draft,submitted,predicted,concluded',
            'organization_id' => 'nullable|integer|exists:organizations,id',
        ]);

        $query = Examination::with([
            'patient:id,patient_identifier',
            'doctor:id,name',
            'organization:id,name',
            'prediction:id,examination_id,status,is_lum_a',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        return response()->json($query->orderByDesc('examined_at')->paginate(15));
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/admin/examinations/{id}",
        tags: ["Admin — Examinations"],
        summary: "Show a single examination with full relationship data",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Examination details",
                content: new OA\JsonContent(ref: "#/components/schemas/ExaminationObject")
            ),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(Examination $examination): JsonResponse
    {
        $examination->load([
            'patient',
            'doctor',
            'organization',
            'prediction.xaiResult',
            'report',
        ]);

        return response()->json($examination);
    }
}
