<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Models\Prediction;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "ReportObject",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer"),
        new OA\Property(property: "examination_id", type: "integer"),
        new OA\Property(property: "prediction_id", type: "integer"),
        new OA\Property(property: "patient_id", type: "integer"),
        new OA\Property(property: "doctor_id", type: "integer"),
        new OA\Property(property: "organization_id", type: "integer"),
        new OA\Property(property: "notes", type: "string", nullable: true),
        new OA\Property(property: "file_path", type: "string", nullable: true),
        new OA\Property(property: "file_name", type: "string", nullable: true),
        new OA\Property(property: "status", type: "string", enum: ["draft", "final"]),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
    ]
)]
class ReportController extends Controller
{
    private function doctor()
    {
        return auth()->user();
    }

    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/doctor/reports",
        tags: ["Doctor — Reports"],
        summary: "List reports authored by this doctor",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "status", in: "query", required: false, schema: new OA\Schema(type: "string", enum: ["draft", "final"])),
            new OA\Parameter(name: "patient_id", in: "query", required: false, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated list of reports",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "data", type: "array", items: new OA\Items(ref: "#/components/schemas/ReportObject")),
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
            'status'     => 'nullable|in:draft,final',
            'patient_id' => 'nullable|integer|exists:patients,id',
        ]);

        $query = Report::where('doctor_id', $this->doctor()->id)
            ->with('patient:id,patient_identifier', 'examination:id,status,examined_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(15));
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/doctor/reports/{id}",
        tags: ["Doctor — Reports"],
        summary: "Show a single report with full context",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Report details",
                content: new OA\JsonContent(ref: "#/components/schemas/ReportObject")
            ),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(Report $report): JsonResponse
    {
        $this->ensureOwnership($report);

        $report->load([
            'patient',
            'examination',
            'prediction.xaiResult',
        ]);

        return response()->json($report);
    }

    // ============================================================
    //  STORE
    // ============================================================

    #[OA\Post(
        path: "/doctor/reports",
        tags: ["Doctor — Reports"],
        summary: "Create a draft report for a concluded examination",
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["examination_id", "prediction_id"],
                properties: [
                    new OA\Property(property: "examination_id", type: "integer"),
                    new OA\Property(property: "prediction_id", type: "integer"),
                    new OA\Property(property: "notes", type: "string", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Report created", content: new OA\JsonContent(ref: "#/components/schemas/ReportObject")),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 422, description: "Examination not concluded or report already exists"),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $doctor = $this->doctor();

        $validated = $request->validate([
            'examination_id' => 'required|integer|exists:examinations,id',
            'prediction_id'  => 'required|integer|exists:predictions,id',
            'notes'          => 'nullable|string',
        ]);

        $examination = Examination::findOrFail($validated['examination_id']);
        abort_if($examination->doctor_id !== $doctor->id, 403, 'This examination does not belong to you.');

        if ($examination->status !== Examination::STATUS_CONCLUDED) {
            return response()->json(['message' => 'A report can only be created for a concluded examination.'], 422);
        }

        if ($examination->report()->exists()) {
            return response()->json(['message' => 'A report already exists for this examination.'], 422);
        }

        $prediction = Prediction::findOrFail($validated['prediction_id']);

        $report = Report::create([
            'examination_id'  => $examination->id,
            'prediction_id'   => $prediction->id,
            'patient_id'      => $examination->patient_id,
            'doctor_id'       => $doctor->id,
            'organization_id' => $doctor->organization_id,
            'notes'           => $validated['notes'] ?? null,
            'status'          => Report::STATUS_DRAFT,
        ]);

        return response()->json($report, 201);
    }

    // ============================================================
    //  UPDATE
    // ============================================================

    #[OA\Put(
        path: "/doctor/reports/{id}",
        tags: ["Doctor — Reports"],
        summary: "Update report notes",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "notes", type: "string", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Report updated", content: new OA\JsonContent(ref: "#/components/schemas/ReportObject")),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 422, description: "Finalized report cannot be edited"),
        ]
    )]
    public function update(Request $request, Report $report): JsonResponse
    {
        $this->ensureOwnership($report);

        if ($report->status === Report::STATUS_FINAL) {
            return response()->json(['message' => 'A finalized report cannot be edited.'], 422);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $report->update($validated);

        return response()->json($report->fresh());
    }

    // ============================================================
    //  FINALIZE
    // ============================================================

    #[OA\Post(
        path: "/doctor/reports/{id}/finalize",
        tags: ["Doctor — Reports"],
        summary: "Finalize a report (marks it as official — no further edits allowed)",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Report finalized"),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 422, description: "Report already finalized"),
        ]
    )]
    public function finalize(Report $report): JsonResponse
    {
        $this->ensureOwnership($report);

        if ($report->status === Report::STATUS_FINAL) {
            return response()->json(['message' => 'Report is already finalized.'], 422);
        }

        $report->update(['status' => Report::STATUS_FINAL]);

        return response()->json(['message' => 'Report finalized.', 'report' => $report->fresh()]);
    }

    // ============================================================
    //  ATTACH FILE
    // ============================================================

    #[OA\Post(
        path: "/doctor/reports/{id}/attach",
        tags: ["Doctor — Reports"],
        summary: "Attach a generated PDF file to the report",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "multipart/form-data",
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: "file", type: "string", format: "binary"),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Report file attached"),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function attachFile(Request $request, Report $report): JsonResponse
    {
        $this->ensureOwnership($report);

        $request->validate([
            'file' => 'required|file|mimes:pdf|max:20480', // 20 MB
        ]);

        // Delete old file if exists
        if ($report->file_path && Storage::disk('local')->exists($report->file_path)) {
            Storage::disk('local')->delete($report->file_path);
        }

        $file = $request->file('file');
        $path = $file->store("reports/{$this->doctor()->organization_id}", 'local');

        $report->update([
            'file_path'  => $path,
            'file_name'  => $file->getClientOriginalName(),
        ]);

        return response()->json(['message' => 'Report file attached.', 'file_path' => $path]);
    }

    // ============================================================
    //  DESTROY
    // ============================================================

    #[OA\Delete(
        path: "/doctor/reports/{id}",
        tags: ["Doctor — Reports"],
        summary: "Delete a draft report",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Report deleted"),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 422, description: "Cannot delete finalized report"),
        ]
    )]
    public function destroy(Report $report): JsonResponse
    {
        $this->ensureOwnership($report);

        if ($report->status === Report::STATUS_FINAL) {
            return response()->json(['message' => 'A finalized report cannot be deleted.'], 422);
        }

        if ($report->file_path) {
            Storage::disk('local')->delete($report->file_path);
        }

        $report->delete();

        return response()->json(['message' => 'Report deleted.']);
    }

    private function ensureOwnership(Report $report): void
    {
        abort_if($report->doctor_id !== $this->doctor()->id, 403, 'This report does not belong to you.');
    }
}
