<?php

namespace App\Services;

use App\Mail\ReportGeneratedMail;
use App\Models\Report;
use App\Models\User;
use App\Models\XaiResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Single source of truth for "finalize a report + email it to the doctor".
 *
 * Both the synchronous prediction flow (DispatchPredictionJob, for clinical-only
 * and local-.pt A6 predictions) and the async webhook flow (PredictionWebhookController,
 * for R2/SVS predictions delivered later by the FastAPI service) must finalize +
 * email the report the same way — previously this logic was only wired up on the
 * webhook path, so predictions that never go through the webhook (the majority —
 * anything without an R2 slide) silently stayed in 'draft' and never emailed.
 */
class ReportMailerService
{
    public static function finalizeAndSend(Report $report): bool
    {
        try {
            $doctor = User::find($report->doctor_id);
            if (!$doctor || !$doctor->email) {
                Log::warning("ReportMailer: Cannot send report email — doctor not found for report #{$report->id}");
                return false;
            }

            if ($report->status !== Report::STATUS_FINAL) {
                $report->update(['status' => Report::STATUS_FINAL]);
            }

            $doctor->load('organization');
            $report->load(['patient', 'prediction.aiModel', 'examination', 'prediction.xaiResult']);

            // Generate presigned GET URLs (1 h) for each XAI image so dompdf fetches
            // them as remote URLs instead of having PHP decode huge base64 blobs.
            $xai       = $report->prediction?->xaiResult;
            $imageUrls = [];
            if ($xai && ($xai->heatmap_path || $xai->segmentation_path || $xai->patches_path)) {
                try {
                    $s3 = self::r2Client();
                    $bucket = config('services.r2.bucket');
                    if ($xai->heatmap_path) {
                        $cmd = $s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $xai->heatmap_path]);
                        $imageUrls['heatmap'] = (string) $s3->createPresignedRequest($cmd, '+1 hour')->getUri();
                    }
                    if ($xai->segmentation_path) {
                        $cmd = $s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $xai->segmentation_path]);
                        $imageUrls['segmentation'] = (string) $s3->createPresignedRequest($cmd, '+1 hour')->getUri();
                    }
                    if ($xai->patches_path) {
                        $cmd = $s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $xai->patches_path]);
                        $imageUrls['patches'] = (string) $s3->createPresignedRequest($cmd, '+1 hour')->getUri();
                    }
                } catch (\Throwable $e) {
                    Log::warning("ReportMailer: Could not presign XAI images for report #{$report->id} — sending without images. {$e->getMessage()}");
                }
            }

            $reportController = new \App\Http\Controllers\Api\Doctor\ReportController();
            $htmlContent = $reportController->generateReportHtml($report, $doctor, $imageUrls);

            // Allow dompdf to fetch remote presigned URLs; raise memory ceiling for large WSI images
            @ini_set('memory_limit', '512M');
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($htmlContent)->setPaper('a4', 'portrait');
            $pdf->getDomPDF()->setOption('isRemoteEnabled', true);
            $pdf->getDomPDF()->setOption('isHtml5ParserEnabled', true);
            $pdfBytes   = $pdf->output();
            $b64Content = base64_encode($pdfBytes);
            $filename   = 'report-' . ($report->patient?->patient_identifier ?? $report->id) . '-' . $report->id . '.pdf';

            Mail::to($doctor->email)->send(new ReportGeneratedMail($report, $doctor, $b64Content, $filename));

            Log::info("ReportMailer: Report email sent to {$doctor->email} for report #{$report->id}");

            // Delete the XAI folder for this prediction from R2 now that report is emailed
            if ($xai) {
                self::deleteR2Prefix(self::xaiPrefix($xai));
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("ReportMailer: Report email FAILED for report #{$report->id}: {$e->getMessage()}");
            return false;
        }
    }

    private static function r2Client(): \Aws\S3\S3Client
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

    private static function xaiPrefix(XaiResult $xai): string
    {
        // Derive the xai/{org}/{patient}/ prefix from any stored R2 key
        $key = $xai->heatmap_path ?? $xai->segmentation_path ?? $xai->patches_path ?? '';
        if (!$key) return '';
        $parts = explode('/', $key);
        // keys are like: xai/{org_id}/{patient_id}/{job_id}_heatmap.png
        return implode('/', array_slice($parts, 0, 3)) . '/';
    }

    private static function deleteR2Prefix(string $prefix): void
    {
        if (!$prefix) return;
        try {
            $s3      = self::r2Client();
            $bucket  = config('services.r2.bucket');
            $objects = [];
            $token   = null;
            do {
                $params = ['Bucket' => $bucket, 'Prefix' => $prefix, 'MaxKeys' => 1000];
                if ($token) $params['ContinuationToken'] = $token;
                $result = $s3->listObjectsV2($params);
                foreach ($result['Contents'] ?? [] as $obj) {
                    $objects[] = ['Key' => $obj['Key']];
                }
                $token = $result['NextContinuationToken'] ?? null;
            } while ($token);

            if (!empty($objects)) {
                $s3->deleteObjects(['Bucket' => $bucket, 'Delete' => ['Objects' => $objects]]);
                Log::info('ReportMailer: Deleted ' . count($objects) . " R2 objects under {$prefix}");
            }
        } catch (\Throwable $e) {
            Log::warning("ReportMailer: R2 prefix delete failed ({$prefix}): {$e->getMessage()}");
        }
    }
}
