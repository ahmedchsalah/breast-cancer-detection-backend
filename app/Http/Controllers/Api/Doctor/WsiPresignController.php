<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * WsiPresignController
 *
 * Handles direct browser → R2 uploads via:
 *   - Single presigned PUT  (files < 100 MB)
 *   - Multipart upload      (files ≥ 100 MB — reliable for large SVS files)
 *
 * Multipart flow:
 *   1. POST /doctor/wsi/presign          → single presigned PUT URL  (small files)
 *   2. POST /doctor/wsi/multipart/init   → uploadId + r2Key
 *   3. POST /doctor/wsi/multipart/parts  → array of presigned part URLs
 *   4. POST /doctor/wsi/multipart/complete → assemble parts in R2
 *   5. POST /doctor/wsi/multipart/abort  → cancel on error
 */
class WsiPresignController extends Controller
{
    private function s3Client(): \Aws\S3\S3Client
    {
        return new \Aws\S3\S3Client([
            'version'                 => 'latest',
            'region'                  => 'auto',
            'endpoint'                => config('services.r2.endpoint'),
            'use_path_style_endpoint' => true,
            'credentials'             => [
                'key'    => config('services.r2.access_key'),
                'secret' => config('services.r2.secret_key'),
            ],
        ]);
    }

    private function r2Key(Request $request): string
    {
        $doctor = auth()->user();
        $ext    = pathinfo($request->filename, PATHINFO_EXTENSION) ?: 'svs';
        return "slides/{$doctor->organization_id}/{$request->patient_id}/"
             . \Illuminate\Support\Str::uuid() . ".{$ext}";
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Single presigned PUT (small files < 100 MB)
    // ─────────────────────────────────────────────────────────────────────────

    public function presign(Request $request): JsonResponse
    {
        $request->validate([
            'filename'   => 'required|string|max:255',
            'patient_id' => 'required|integer|exists:patients,id',
        ]);

        $r2Key = $this->r2Key($request);
        $s3    = $this->s3Client();

        $cmd = $s3->getCommand('PutObject', [
            'Bucket'      => config('services.r2.bucket'),
            'Key'         => $r2Key,
            'ContentType' => 'application/octet-stream',
        ]);

        $presignedUrl = (string) $s3->createPresignedRequest($cmd, '+60 minutes')->getUri();

        return response()->json([
            'presigned_url' => $presignedUrl,
            'r2_key'        => $r2Key,
            'expires_in'    => 3600,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Multipart — Step 1: Initiate
    // ─────────────────────────────────────────────────────────────────────────

    public function multipartInit(Request $request): JsonResponse
    {
        $request->validate([
            'filename'   => 'required|string|max:255',
            'patient_id' => 'required|integer|exists:patients,id',
        ]);

        $r2Key = $this->r2Key($request);
        $s3    = $this->s3Client();

        $result = $s3->createMultipartUpload([
            'Bucket'      => config('services.r2.bucket'),
            'Key'         => $r2Key,
            'ContentType' => 'application/octet-stream',
        ]);

        \Illuminate\Support\Facades\Log::info('[R2 Multipart] Initiated', [
            'upload_id' => $result['UploadId'],
            'r2_key'    => $r2Key,
        ]);

        return response()->json([
            'upload_id' => $result['UploadId'],
            'r2_key'    => $r2Key,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Multipart — Step 2: Get presigned URLs for each part
    // ─────────────────────────────────────────────────────────────────────────

    public function multipartParts(Request $request): JsonResponse
    {
        $request->validate([
            'upload_id'  => 'required|string',
            'r2_key'     => 'required|string',
            'part_count' => 'required|integer|min:1|max:10000',
        ]);

        $s3     = $this->s3Client();
        $bucket = config('services.r2.bucket');
        $urls   = [];

        for ($i = 1; $i <= $request->part_count; $i++) {
            $cmd = $s3->getCommand('UploadPart', [
                'Bucket'     => $bucket,
                'Key'        => $request->r2_key,
                'UploadId'   => $request->upload_id,
                'PartNumber' => $i,
            ]);
            $urls[] = (string) $s3->createPresignedRequest($cmd, '+120 minutes')->getUri();
        }

        \Illuminate\Support\Facades\Log::info('[R2 Multipart] Generated part URLs', [
            'upload_id'  => $request->upload_id,
            'r2_key'     => $request->r2_key,
            'part_count' => $request->part_count,
            'url_sample' => substr($urls[0] ?? '', 0, 120),
        ]);

        return response()->json(['part_urls' => $urls]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Multipart — Step 3: Complete
    // ─────────────────────────────────────────────────────────────────────────

    public function multipartComplete(Request $request): JsonResponse
    {
        $request->validate([
            'upload_id' => 'required|string',
            'r2_key'    => 'required|string',
            'parts'     => 'required|array|min:1',
            'parts.*.PartNumber' => 'required|integer',
            'parts.*.ETag'       => 'required|string',
        ]);

        $s3 = $this->s3Client();

        // Ensure ETags are properly quoted — TrimStrings middleware may strip quotes
        $parts = array_map(function ($part) {
            $etag = trim($part['ETag']);
            // Strip any existing quotes then re-wrap — handles double-quoting
            $etag = trim($etag, '"');
            $etag = '"' . $etag . '"';
            return ['PartNumber' => (int) $part['PartNumber'], 'ETag' => $etag];
        }, $request->parts);

        \Illuminate\Support\Facades\Log::info('[R2 Multipart] Completing upload', [
            'upload_id'   => $request->upload_id,
            'r2_key'      => $request->r2_key,
            'part_count'  => count($parts),
            'parts_sample'=> array_slice($parts, 0, 3),
        ]);

        $s3->completeMultipartUpload([
            'Bucket'          => config('services.r2.bucket'),
            'Key'             => $request->r2_key,
            'UploadId'        => $request->upload_id,
            'MultipartUpload' => ['Parts' => $parts],
        ]);

        return response()->json(['message' => 'Upload complete.', 'r2_key' => $request->r2_key]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Multipart — Abort on error
    // ─────────────────────────────────────────────────────────────────────────

    public function multipartAbort(Request $request): JsonResponse
    {
        $request->validate([
            'upload_id' => 'required|string',
            'r2_key'    => 'required|string',
        ]);

        try {
            $this->s3Client()->abortMultipartUpload([
                'Bucket'   => config('services.r2.bucket'),
                'Key'      => $request->r2_key,
                'UploadId' => $request->upload_id,
            ]);
        } catch (\Throwable $e) {
            // Best-effort — don't fail the response
        }

        return response()->json(['message' => 'Upload aborted.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Delete slide from R2
    // ─────────────────────────────────────────────────────────────────────────

    public function deleteSlide(Request $request): JsonResponse
    {
        $request->validate(['r2_key' => 'required|string']);

        $key = $request->r2_key;
        if (!str_starts_with($key, 'slides/')) {
            return response()->json(['message' => 'Invalid key.'], 422);
        }

        Storage::disk('r2')->delete($key);
        return response()->json(['message' => 'Slide deleted from storage.']);
    }
}
