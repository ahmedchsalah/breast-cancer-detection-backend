<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * WsiPresignController
 *
 * Generates presigned URLs for direct browser → R2 uploads.
 * The browser uploads the SVS directly to R2 without going through Laravel,
 * then tells Laravel the R2 key so FastAPI can process it.
 */
class WsiPresignController extends Controller
{
    /**
     * POST /doctor/wsi/presign
     *
     * Returns a presigned PUT URL for direct browser upload to R2.
     * The URL expires in 30 minutes.
     */
    public function presign(Request $request): JsonResponse
    {
        $request->validate([
            'filename'   => 'required|string|max:255',
            'patient_id' => 'required|integer|exists:patients,id',
        ]);

        $doctor    = auth()->user();
        $ext       = pathinfo($request->filename, PATHINFO_EXTENSION);
        $r2Key     = "slides/{$doctor->organization_id}/{$request->patient_id}/" . Str::uuid() . ".{$ext}";

        // Generate presigned PUT URL (30 min expiry)
        $presignedUrl = Storage::disk('r2')->temporaryUploadUrl(
            $r2Key,
            now()->addMinutes(30),
            ['ContentType' => 'application/octet-stream']
        );

        return response()->json([
            'presigned_url' => $presignedUrl,
            'r2_key'        => $r2Key,
            'expires_in'    => 1800,
        ]);
    }

    /**
     * DELETE /doctor/wsi/r2/{key}
     *
     * Manually delete a slide from R2 (called after processing completes).
     */
    public function deleteSlide(Request $request): JsonResponse
    {
        $request->validate(['r2_key' => 'required|string']);

        $key = $request->r2_key;

        // Security: only allow deleting from slides/ prefix
        if (!str_starts_with($key, 'slides/')) {
            return response()->json(['message' => 'Invalid key.'], 422);
        }

        Storage::disk('r2')->delete($key);

        return response()->json(['message' => 'Slide deleted from storage.']);
    }
}
