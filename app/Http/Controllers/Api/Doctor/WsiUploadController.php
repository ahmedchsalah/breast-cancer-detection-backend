<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\WsiUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "WsiUploadObject",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer"),
        new OA\Property(property: "patient_id", type: "integer"),
        new OA\Property(property: "uploaded_by", type: "integer"),
        new OA\Property(property: "organization_id", type: "integer"),
        new OA\Property(property: "file_path", type: "string"),
        new OA\Property(property: "original_name", type: "string"),
        new OA\Property(property: "file_size_bytes", type: "integer"),
        new OA\Property(property: "mime_type", type: "string"),
        new OA\Property(property: "status", type: "string", enum: ["pending", "processing", "ready", "failed"]),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
    ]
)]
class WsiUploadController extends Controller
{
    private function doctor()
    {
        return auth()->user();
    }

    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/doctor/wsi-uploads",
        tags: ["Doctor — WSI"],
        summary: "List WSI uploads for the doctor's organization",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "patient_id", in: "query", required: false, schema: new OA\Schema(type: "integer")),
            new OA\Parameter(name: "status", in: "query", required: false, schema: new OA\Schema(type: "string", enum: ["pending", "processing", "ready", "failed"])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated list of WSI uploads",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "data", type: "array", items: new OA\Items(ref: "#/components/schemas/WsiUploadObject")),
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
            'patient_id' => 'nullable|integer|exists:patients,id',
            'status'     => 'nullable|in:pending,processing,ready,failed',
        ]);

        $query = WsiUpload::where('organization_id', $this->doctor()->organization_id)
            ->with('patient:id,patient_identifier', 'uploader:id,name');

        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(15));
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/doctor/wsi-uploads/{id}",
        tags: ["Doctor — WSI"],
        summary: "Show a single WSI upload record",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "WSI details",
                content: new OA\JsonContent(ref: "#/components/schemas/WsiUploadObject")
            ),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(WsiUpload $wsiUpload): JsonResponse
    {
        $this->ensureSameOrg($wsiUpload);

        return response()->json($wsiUpload->load('patient:id,patient_identifier', 'uploader:id,name'));
    }

    // ============================================================
    //  STORE
    // ============================================================

    #[OA\Post(
        path: "/doctor/wsi-uploads",
        tags: ["Doctor — WSI"],
        summary: "Upload a WSI file for a patient",
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "multipart/form-data",
                schema: new OA\Schema(
                    required: ["patient_id", "file"],
                    properties: [
                        new OA\Property(property: "patient_id", type: "integer"),
                        new OA\Property(property: "file", type: "string", format: "binary"),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "WSI uploaded", content: new OA\JsonContent(ref: "#/components/schemas/WsiUploadObject")),
            new OA\Response(response: 403, description: "Patient mismatch"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $doctor = $this->doctor();

        $validated = $request->validate([
            'patient_id' => 'required|integer|exists:patients,id',
            'file'       => 'required|file|mimes:tiff,svs,ndpi,scn,mrxs,vms,vmu,bif,btf|max:2097152', // 2 GB
        ]);

        $patient = Patient::findOrFail($validated['patient_id']);
        abort_if($patient->organization_id !== $doctor->organization_id, 403, 'Patient does not belong to your organization.');

        $file = $request->file('file');
        $path = $file->store("wsi/{$doctor->organization_id}/{$patient->id}", 'local');

        $upload = WsiUpload::create([
            'patient_id'      => $patient->id,
            'uploaded_by'     => $doctor->id,
            'organization_id' => $doctor->organization_id,
            'file_path'       => $path,
            'original_name'   => $file->getClientOriginalName(),
            'file_size_bytes' => $file->getSize(),
            'mime_type'       => $file->getMimeType(),
            'status'          => 'pending',
        ]);

        return response()->json($upload, 201);
    }

    // ============================================================
    //  DESTROY
    // ============================================================

    #[OA\Delete(
        path: "/doctor/wsi-uploads/{id}",
        tags: ["Doctor — WSI"],
        summary: "Delete a WSI upload (only if no prediction has been made from it)",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "WSI deleted"),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 422, description: "Cannot delete WSI with associated prediction"),
        ]
    )]
    public function destroy(WsiUpload $wsiUpload): JsonResponse
    {
        $this->ensureSameOrg($wsiUpload);

        if ($wsiUpload->prediction()->exists()) {
            return response()->json(['message' => 'Cannot delete a WSI that has an associated prediction.'], 422);
        }

        Storage::disk('local')->delete($wsiUpload->file_path);
        $wsiUpload->delete();

        return response()->json(['message' => 'WSI file deleted.']);
    }

    private function ensureSameOrg(WsiUpload $wsiUpload): void
    {
        abort_if($wsiUpload->organization_id !== $this->doctor()->organization_id, 403, 'This upload does not belong to your organization.');
    }
}
