<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "ExaminationObject",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer"),
        new OA\Property(property: "patient_id", type: "integer"),
        new OA\Property(property: "doctor_id", type: "integer"),
        new OA\Property(property: "organization_id", type: "integer"),
        new OA\Property(property: "status", type: "string", enum: ["draft", "submitted", "predicted", "concluded"]),
        new OA\Property(property: "chief_complaint", type: "string", nullable: true),
        new OA\Property(property: "clinical_notes", type: "string", nullable: true),
        new OA\Property(property: "doctor_conclusion", type: "string", nullable: true),
        new OA\Property(property: "examined_at", type: "string", format: "date-time", nullable: true),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
    ]
)]
class ExaminationController extends Controller
{
    private function doctor()
    {
        return auth()->user();
    }

    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/doctor/examinations",
        tags: ["Doctor — Examinations"],
        summary: "List examinations created by this doctor",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "status", in: "query", required: false, schema: new OA\Schema(type: "string", enum: ["draft", "submitted", "predicted", "concluded"])),
            new OA\Parameter(name: "patient_id", in: "query", required: false, schema: new OA\Schema(type: "integer")),
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
            'status'     => 'nullable|in:draft,submitted,predicted,concluded',
            'patient_id' => 'nullable|integer|exists:patients,id',
        ]);

        $query = Examination::where('doctor_id', $this->doctor()->id)
            ->with('patient:id,patient_identifier,age', 'prediction:id,examination_id,is_lum_a,status')
            ->orderByDesc('examined_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }

        return response()->json($query->paginate(15));
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/doctor/examinations/{id}",
        tags: ["Doctor — Examinations"],
        summary: "Show a single examination with all related data",
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
            new OA\Response(response: 403, description: "Not authorized to access this examination"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(Examination $examination): JsonResponse
    {
        $this->ensureOwnership($examination);

        $examination->load([
            'patient',
            'prediction.xaiResult',
            'report',
        ]);

        return response()->json($examination);
    }

    // ============================================================
    //  STORE
    // ============================================================

    #[OA\Post(
        path: "/doctor/examinations",
        tags: ["Doctor — Examinations"],
        summary: "Open a new examination for a patient",
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["patient_id"],
                properties: [
                    new OA\Property(property: "patient_id", type: "integer"),
                    new OA\Property(property: "chief_complaint", type: "string", nullable: true),
                    new OA\Property(property: "clinical_notes", type: "string", nullable: true),
                    new OA\Property(property: "examined_at", type: "string", format: "date", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Examination created", content: new OA\JsonContent(ref: "#/components/schemas/ExaminationObject")),
            new OA\Response(response: 403, description: "Patient does not belong to your organization"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $doctor = $this->doctor();

        $validated = $request->validate([
            'patient_id'       => 'required|integer|exists:patients,id',
            'chief_complaint'  => 'nullable|string|max:1000',
            'clinical_notes'   => 'nullable|string',
            'examined_at'      => 'nullable|date',
        ]);

        // Ensure patient belongs to same org
        $patient = Patient::findOrFail($validated['patient_id']);
        abort_if($patient->organization_id !== $doctor->organization_id, 403, 'Patient does not belong to your organization.');

        $examination = Examination::create([
            ...$validated,
            'doctor_id'       => $doctor->id,
            'organization_id' => $doctor->organization_id,
            'status'          => Examination::STATUS_DRAFT,
            'examined_at'     => $validated['examined_at'] ?? now(),
        ]);

        return response()->json($examination, 201);
    }

    // ============================================================
    //  UPDATE
    // ============================================================

    #[OA\Put(
        path: "/doctor/examinations/{id}",
        tags: ["Doctor — Examinations"],
        summary: "Update examination notes/complaint (only while in draft/submitted state)",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "chief_complaint", type: "string", nullable: true),
                    new OA\Property(property: "clinical_notes", type: "string", nullable: true),
                    new OA\Property(property: "doctor_conclusion", type: "string", nullable: true),
                    new OA\Property(property: "examined_at", type: "string", format: "date", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Examination updated", content: new OA\JsonContent(ref: "#/components/schemas/ExaminationObject")),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 422, description: "Cannot modify a concluded examination"),
        ]
    )]
    public function update(Request $request, Examination $examination): JsonResponse
    {
        $this->ensureOwnership($examination);

        if ($examination->status === Examination::STATUS_CONCLUDED) {
            return response()->json(['message' => 'A concluded examination cannot be modified.'], 422);
        }

        $validated = $request->validate([
            'chief_complaint'    => 'nullable|string|max:1000',
            'clinical_notes'     => 'nullable|string',
            'doctor_conclusion'  => 'nullable|string',
            'examined_at'        => 'nullable|date',
        ]);

        $examination->update($validated);

        return response()->json($examination->fresh());
    }

    // ============================================================
    //  SUBMIT
    // ============================================================

    #[OA\Post(
        path: "/doctor/examinations/{id}/submit",
        tags: ["Doctor — Examinations"],
        summary: "Submit an examination (moves it from draft to submitted, ready for prediction)",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Examination submitted"),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 422, description: "Only draft examinations can be submitted"),
        ]
    )]
    public function submit(Examination $examination): JsonResponse
    {
        $this->ensureOwnership($examination);

        if ($examination->status !== Examination::STATUS_DRAFT) {
            return response()->json(['message' => 'Only draft examinations can be submitted.'], 422);
        }

        $examination->update(['status' => Examination::STATUS_SUBMITTED]);

        return response()->json(['message' => 'Examination submitted successfully.', 'examination' => $examination]);
    }

    // ============================================================
    //  CONCLUDE
    // ============================================================

    #[OA\Post(
        path: "/doctor/examinations/{id}/conclude",
        tags: ["Doctor — Examinations"],
        summary: "Conclude an examination (doctor has reviewed the AI result and written their conclusion)",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["doctor_conclusion"],
                properties: [
                    new OA\Property(property: "doctor_conclusion", type: "string"),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Examination concluded"),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 422, description: "Can only be concluded after prediction"),
        ]
    )]
    public function conclude(Request $request, Examination $examination): JsonResponse
    {
        $this->ensureOwnership($examination);

        if ($examination->status !== Examination::STATUS_PREDICTED) {
            return response()->json(['message' => 'Examination can only be concluded after a prediction has been made.'], 422);
        }

        $validated = $request->validate([
            'doctor_conclusion' => 'required|string',
        ]);

        $examination->update([
            'doctor_conclusion' => $validated['doctor_conclusion'],
            'status'            => Examination::STATUS_CONCLUDED,
        ]);

        return response()->json(['message' => 'Examination concluded.', 'examination' => $examination->fresh()]);
    }

    // ============================================================
    //  DESTROY
    // ============================================================

    #[OA\Delete(
        path: "/doctor/examinations/{id}",
        tags: ["Doctor — Examinations"],
        summary: "Delete a draft examination",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Examination deleted"),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 422, description: "Only draft examinations can be deleted"),
        ]
    )]
    public function destroy(Examination $examination): JsonResponse
    {
        $this->ensureOwnership($examination);

        if ($examination->status !== Examination::STATUS_DRAFT) {
            return response()->json(['message' => 'Only draft examinations can be deleted.'], 422);
        }

        $examination->delete();

        return response()->json(['message' => 'Examination deleted.']);
    }

    private function ensureOwnership(Examination $examination): void
    {
        abort_if($examination->doctor_id !== $this->doctor()->id, 403, 'You do not have access to this examination.');
    }
}
