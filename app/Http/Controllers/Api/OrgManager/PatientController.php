<?php

namespace App\Http\Controllers\Api\OrgManager;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PatientController extends Controller
{
    private function orgId(): int
    {
        return auth()->user()->organization_id;
    }

    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/org-manager/patients",
        tags: ["OrgManager — Patients"],
        summary: "List all patients in this organization",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "search", in: "query", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "her2", in: "query", required: false, schema: new OA\Schema(type: "boolean")),
            new OA\Parameter(name: "er_status", in: "query", required: false, schema: new OA\Schema(type: "boolean")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated list of patients",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "data", type: "array", items: new OA\Items(ref: "#/components/schemas/PatientObject")),
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
            'search'   => 'nullable|string|max:100',
            'her2'     => 'nullable|boolean',
            'er_status'=> 'nullable|boolean',
        ]);

        $query = Patient::where('organization_id', $this->orgId())
            ->withCount('examinations', 'predictions');

        if ($request->filled('search')) {
            $query->where('patient_identifier', 'like', '%' . $request->search . '%');
        }
        if ($request->filled('her2')) {
            $query->where('her2_binary', filter_var($request->her2, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('er_status')) {
            $query->where('er_status', filter_var($request->er_status, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/org-manager/patients/{id}",
        tags: ["OrgManager — Patients"],
        summary: "Show a single patient with their examination history",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Patient details",
                content: new OA\JsonContent(ref: "#/components/schemas/PatientObject")
            ),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(Patient $patient): JsonResponse
    {
        abort_if($patient->organization_id !== $this->orgId(), 403, 'This patient does not belong to your organization.');

        $patient->load(['examinations' => fn($q) => $q->with('prediction:id,examination_id,is_lum_a,status,completed_at')->orderByDesc('examined_at')])
            ->loadCount('examinations', 'predictions');

        return response()->json($patient);
    }
}
