<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AdminPatientController extends Controller
{
    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/admin/patients",
        tags: ["Admin — Patients"],
        summary: "List all patients platform-wide with filtering",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "organization_id", in: "query", required: false, schema: new OA\Schema(type: "integer")),
            new OA\Parameter(name: "search",          in: "query", required: false, schema: new OA\Schema(type: "string", description: "Search by patient_identifier")),
            new OA\Parameter(name: "age_min",         in: "query", required: false, schema: new OA\Schema(type: "integer")),
            new OA\Parameter(name: "age_max",         in: "query", required: false, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated list of patients",
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
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'search'          => 'nullable|string|max:100',
            'age_min'         => 'nullable|integer|min:0',
            'age_max'         => 'nullable|integer|min:0',
        ]);

        $query = Patient::with('organization:id,name')
            ->orderByDesc('created_at');

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }
        if ($request->filled('search')) {
            $query->where('patient_identifier', 'like', '%' . $request->search . '%');
        }
        if ($request->filled('age_min')) {
            $query->where('age', '>=', $request->age_min);
        }
        if ($request->filled('age_max')) {
            $query->where('age', '<=', $request->age_max);
        }

        return response()->json($query->paginate(15));
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/admin/patients/{id}",
        tags: ["Admin — Patients"],
        summary: "Show a single patient with organization details",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Patient details"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(Patient $patient): JsonResponse
    {
        return response()->json($patient->load('organization'));
    }
}
