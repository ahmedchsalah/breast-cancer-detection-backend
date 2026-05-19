<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\WsiUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
    //  STORE FROM R2 KEY (slide already uploaded to R2 by browser)
    // ============================================================

    public function storeFromR2Key(Request $request): JsonResponse
    {
        $doctor = $this->doctor();

        $validated = $request->validate([
            'patient_id'    => 'required|integer|exists:patients,id',
            'r2_key'        => 'required|string|max:500',
            'original_name' => 'nullable|string|max:255',
        ]);

        $patient = Patient::findOrFail($validated['patient_id']);
        abort_if($patient->organization_id !== $doctor->organization_id, 403, 'Patient does not belong to your organization.');

        $upload = WsiUpload::create([
            'patient_id'      => $patient->id,
            'uploaded_by'     => $doctor->id,
            'organization_id' => $doctor->organization_id,
            'file_path'       => $validated['r2_key'], // store r2_key as file_path for reference
            'original_name'   => $validated['original_name'] ?? 'slide.svs',
            'file_size_bytes' => null,
            'mime_type'       => 'application/octet-stream',
            'status'          => 'pending', // FastAPI will process it
            'r2_key'          => $validated['r2_key'],
        ]);

        return response()->json($upload, 201);
    }

    // ============================================================
    //  STORE FROM FEATURES (base64 JSON — bypasses PHP upload limit)
    // ============================================================

    /**
     * POST /doctor/wsi-uploads/from-features
     *
     * Accepts pre-extracted CONCH features as a base64-encoded .pt string.
     * This avoids PHP's upload_max_filesize limit entirely since it's JSON.
     * The frontend calls FastAPI /extract/wsi directly, gets back pt_b64,
     * then sends it here to register the upload record.
     */
    public function storeFromFeatures(Request $request): JsonResponse
    {
        $doctor = $this->doctor();

        $validated = $request->validate([
            'patient_id'    => 'required|integer|exists:patients,id',
            'pt_b64'        => 'required|string',
            'original_name' => 'nullable|string|max:255',
        ]);

        $patient = Patient::findOrFail($validated['patient_id']);
        abort_if($patient->organization_id !== $doctor->organization_id, 403, 'Patient does not belong to your organization.');

        // Decode base64 and save as .pt file
        $ptBytes = base64_decode($validated['pt_b64']);
        if ($ptBytes === false || strlen($ptBytes) < 100) {
            return response()->json(['message' => 'Invalid or empty feature data.'], 422);
        }

        $path = "features/{$doctor->organization_id}/{$patient->id}_" . uniqid() . ".pt";
        Storage::disk(config('filesystems.default'))->put($path, $ptBytes);

        $upload = WsiUpload::create([
            'patient_id'            => $patient->id,
            'uploaded_by'           => $doctor->id,
            'organization_id'       => $doctor->organization_id,
            'file_path'             => $path,
            'original_name'         => $validated['original_name'] ?? 'features.pt',
            'file_size_bytes'       => strlen($ptBytes),
            'mime_type'             => 'application/octet-stream',
            'status'                => 'ready',
            'features_path'         => $path,
            'features_extracted_at' => now(),
        ]);

        return response()->json($upload, 201);
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
            'file'       => 'required|file|max:51200', // 50 MB max — .pt files are small (~2-5 MB)
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
            // If it's a .pt file, features are already extracted — mark as ready
            'status'          => str_ends_with($file->getClientOriginalName(), '.pt') ? 'ready' : 'pending',
            'features_path'   => str_ends_with($file->getClientOriginalName(), '.pt') ? $path : null,
            'features_extracted_at' => str_ends_with($file->getClientOriginalName(), '.pt') ? now() : null,
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

    // ============================================================
    //  EXTRACT FEATURES
    // ============================================================

    #[OA\Post(
        path: "/doctor/wsi-uploads/{id}/extract-features",
        tags: ["Doctor — WSI"],
        summary: "Extract CONCH features from a WSI (Simulated or via ZIP)",
        description: "Calls FastAPI /extract with patch images and saves the .pt result to local storage.",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Features extracted and saved"),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 503, description: "FastAPI unavailable"),
        ]
    )]
    public function extractFeatures(Request $request, WsiUpload $wsiUpload): JsonResponse
    {
        $this->ensureSameOrg($wsiUpload);

        // ── Simulation Mode ──────────────────────────────────────────────────
        // Allows developers to test the Fusion (A6) pipeline without real slides.
        if ($request->query('simulate') === '1') {
            Log::info("[BReCAI] Simulating feature extraction for WSI #{$wsiUpload->id}");

            // Create a dummy .pt path
            $dummyPath = "features/{$wsiUpload->id}_mock.pt";
            
            // In a real PyTorch environment, this would be a torch.save()
            // Here we just write a small binary blob that looks like a 1x512 float16 tensor
            // This is enough to satisfy the "exists" check in DispatchPredictionJob.
            // Note: The FastAPI space might fail if the file is truly corrupt, 
            // but for a pure "flow" test, this works.
            Storage::disk(config('filesystems.default'))->put($dummyPath, "DUMMY_CONCH_FEATURES_TENSOR");

            $wsiUpload->update([
                'status'                => 'ready',
                'features_path'         => $dummyPath,
                'features_extracted_at' => now(),
            ]);

            return response()->json([
                'message' => 'Features simulated successfully. You can now run a Fusion (A6) prediction.',
                'wsi'     => $wsiUpload
            ]);
        }

        // ── Real Extraction Mode ─────────────────────────────────────────────
        $fastApiBase = rtrim(config('services.brecai.url'), '/');
        
        Log::info("Feature extraction requested for WSI #{$wsiUpload->id}");
        
        return response()->json([
            'message' => 'Real feature extraction requires a ZIP of patches. Please use the /extract endpoint in the "BReCAI FastAPI" group to generate the .pt file, then upload it to this WSI record.',
            'wsi_id'  => $wsiUpload->id,
            'status'  => 'pending'
        ]);
    }

    private function ensureSameOrg(WsiUpload $wsiUpload): void
    {
        abort_if($wsiUpload->organization_id !== $this->doctor()->organization_id, 403, 'This upload does not belong to your organization.');
    }
}
