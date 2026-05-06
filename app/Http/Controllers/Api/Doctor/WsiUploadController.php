<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\WsiUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WsiUploadController extends Controller
{
    private function doctor()
    {
        return auth()->user();
    }

    /**
     * List WSI uploads for the doctor's organization.
     */
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

    /**
     * Show a single WSI upload record.
     */
    public function show(WsiUpload $wsiUpload): JsonResponse
    {
        $this->ensureSameOrg($wsiUpload);

        return response()->json($wsiUpload->load('patient:id,patient_identifier', 'uploader:id,name'));
    }

    /**
     * Upload a WSI file for a patient.
     */
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

    /**
     * Delete a WSI upload (only if no prediction has been made from it).
     */
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
