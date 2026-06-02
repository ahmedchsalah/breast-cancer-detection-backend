<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "PatientObject",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer"),
        new OA\Property(property: "organization_id", type: "integer"),
        new OA\Property(property: "patient_identifier", type: "string"),
        new OA\Property(property: "er_status", type: "boolean"),
        new OA\Property(property: "pr_status", type: "boolean"),
        new OA\Property(property: "her2_binary", type: "boolean"),
        new OA\Property(property: "age", type: "integer"),
        new OA\Property(property: "stage_num", type: "integer"),
        new OA\Property(property: "er_status_missing", type: "boolean"),
        new OA\Property(property: "pr_status_missing", type: "boolean"),
        new OA\Property(property: "fraction_genome_altered", type: "number", format: "float", nullable: true),
        new OA\Property(property: "buffa_hypoxia_score", type: "number", format: "float", nullable: true),
        new OA\Property(property: "ragnum_hypoxia_score", type: "number", format: "float", nullable: true),
        new OA\Property(property: "winter_hypoxia_score", type: "number", format: "float", nullable: true),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
    ]
)]
class PatientController extends Controller
{
    private function doctor()
    {
        return auth()->user();
    }

    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/doctor/patients",
        tags: ["Doctor — Patients"],
        summary: "List patients belonging to this doctor's organization",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "search", in: "query", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "her2", in: "query", required: false, schema: new OA\Schema(type: "boolean")),
            new OA\Parameter(name: "er_status", in: "query", required: false, schema: new OA\Schema(type: "boolean")),
            new OA\Parameter(name: "stage_num", in: "query", required: false, schema: new OA\Schema(type: "integer", enum: [1, 2, 3, 4])),
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
            'search'    => 'nullable|string|max:100',
            'her2'      => 'nullable|boolean',
            'er_status' => 'nullable|boolean',
            'stage_num' => 'nullable|integer|between:1,4',
        ]);

        $query = Patient::where('organization_id', $this->doctor()->organization_id)
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
        if ($request->filled('stage_num')) {
            $query->where('stage_num', $request->stage_num);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/doctor/patients/{id}",
        tags: ["Doctor — Patients"],
        summary: "Show a patient with their full examination & prediction history",
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
            new OA\Response(response: 403, description: "Not authorized to access this patient"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(Patient $patient): JsonResponse
    {
        $this->ensureSameOrg($patient);

        $patient->load([
            'examinations' => fn($q) => $q->with([
                'prediction:id,examination_id,is_lum_a,confidence_lum_a,confidence_non_lum_a,status,completed_at',
                'report:id,examination_id,status,file_path',
            ])->orderByDesc('examined_at'),
        ])->loadCount('examinations', 'predictions');

        return response()->json($patient);
    }

    // ============================================================
    //  STORE
    // ============================================================

    #[OA\Post(
        path: "/doctor/patients",
        tags: ["Doctor — Patients"],
        summary: "Register a new patient",
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["patient_identifier", "er_status", "pr_status", "her2_binary", "age", "stage_num"],
                properties: [
                    new OA\Property(property: "patient_identifier", type: "string"),
                    new OA\Property(property: "er_status", type: "boolean"),
                    new OA\Property(property: "pr_status", type: "boolean"),
                    new OA\Property(property: "her2_binary", type: "boolean"),
                    new OA\Property(property: "age", type: "integer"),
                    new OA\Property(property: "stage_num", type: "integer", enum: [1, 2, 3, 4]),
                    new OA\Property(property: "er_status_missing", type: "boolean", nullable: true),
                    new OA\Property(property: "pr_status_missing", type: "boolean", nullable: true),
                    new OA\Property(property: "fraction_genome_altered", type: "number", format: "float", nullable: true),
                    new OA\Property(property: "buffa_hypoxia_score", type: "number", format: "float", nullable: true),
                    new OA\Property(property: "ragnum_hypoxia_score", type: "number", format: "float", nullable: true),
                    new OA\Property(property: "winter_hypoxia_score", type: "number", format: "float", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Patient created", content: new OA\JsonContent(ref: "#/components/schemas/PatientObject")),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_identifier'      => 'nullable|string|max:50|unique:patients,patient_identifier',
            'er_status'               => 'required|boolean',
            'pr_status'               => 'required|boolean',
            'her2_binary'             => 'required|boolean',
            'age'                     => 'required|integer|min:0|max:120',
            'stage_num'               => 'required|integer|between:1,4',
            'er_status_missing'       => 'nullable|boolean',
            'pr_status_missing'       => 'nullable|boolean',
            'fraction_genome_altered' => 'nullable|numeric|between:0,1',
            'buffa_hypoxia_score'     => 'nullable|numeric',
            'ragnum_hypoxia_score'    => 'nullable|numeric',
            'winter_hypoxia_score'    => 'nullable|numeric',
        ]);

        $validated['organization_id'] = $this->doctor()->organization_id;

        // Auto-generate BRECAI-FED identifier if not provided
        if (empty($validated['patient_identifier'])) {
            $validated['patient_identifier'] = Patient::generateIdentifier($validated['organization_id']);
        }

        $patient = Patient::create($validated);

        return response()->json($patient, 201);
    }

    // ============================================================
    //  UPDATE
    // ============================================================

    #[OA\Put(
        path: "/doctor/patients/{id}",
        tags: ["Doctor — Patients"],
        summary: "Update clinical data for a patient",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "er_status", type: "boolean"),
                    new OA\Property(property: "pr_status", type: "boolean"),
                    new OA\Property(property: "her2_binary", type: "boolean"),
                    new OA\Property(property: "age", type: "integer"),
                    new OA\Property(property: "stage_num", type: "integer", enum: [1, 2, 3, 4]),
                    new OA\Property(property: "er_status_missing", type: "boolean", nullable: true),
                    new OA\Property(property: "pr_status_missing", type: "boolean", nullable: true),
                    new OA\Property(property: "fraction_genome_altered", type: "number", format: "float", nullable: true),
                    new OA\Property(property: "buffa_hypoxia_score", type: "number", format: "float", nullable: true),
                    new OA\Property(property: "ragnum_hypoxia_score", type: "number", format: "float", nullable: true),
                    new OA\Property(property: "winter_hypoxia_score", type: "number", format: "float", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Patient updated", content: new OA\JsonContent(ref: "#/components/schemas/PatientObject")),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 404, description: "Not found"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function update(Request $request, Patient $patient): JsonResponse
    {
        $this->ensureSameOrg($patient);

        $validated = $request->validate([
            'er_status'               => 'sometimes|boolean',
            'pr_status'               => 'sometimes|boolean',
            'her2_binary'             => 'sometimes|boolean',
            'age'                     => 'sometimes|integer|min:0|max:120',
            'stage_num'               => 'sometimes|integer|between:1,4',
            'er_status_missing'       => 'nullable|boolean',
            'pr_status_missing'       => 'nullable|boolean',
            'fraction_genome_altered' => 'nullable|numeric|between:0,1',
            'buffa_hypoxia_score'     => 'nullable|numeric',
            'ragnum_hypoxia_score'    => 'nullable|numeric',
            'winter_hypoxia_score'    => 'nullable|numeric',
        ]);

        $patient->update($validated);

        return response()->json($patient->fresh());
    }

    // ============================================================
    //  DESTROY
    // ============================================================

    #[OA\Delete(
        path: "/doctor/patients/{id}",
        tags: ["Doctor — Patients"],
        summary: "Delete a patient record (only if they have no predictions)",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Patient deleted"),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 422, description: "Cannot delete a patient with prediction records"),
        ]
    )]
    public function destroy(Patient $patient): JsonResponse
    {
        $this->ensureSameOrg($patient);

        if ($patient->predictions()->exists()) {
            return response()->json(['message' => 'Cannot delete a patient that has prediction records.'], 422);
        }

        $patient->delete();

        return response()->json(['message' => 'Patient deleted successfully.']);
    }

    private function ensureSameOrg(Patient $patient): void
    {
        abort_if($patient->organization_id !== $this->doctor()->organization_id, 403, 'Patient does not belong to your organization.');
    }
}
