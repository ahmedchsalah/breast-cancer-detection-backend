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

        // Attach presigned URLs for heatmap and segmentation
        $reportArr = $report->toArray();
        $xaiResult = $report->prediction?->xaiResult;
        $heatmapPath = $xaiResult?->heatmap_path;
        $segmentationPath = $xaiResult?->segmentation_path;
        $patchesPath = $xaiResult?->patches_path;

        if ($heatmapPath || $segmentationPath || $patchesPath) {
            try {
                $s3 = new \Aws\S3\S3Client([
                    'version'                 => 'latest',
                    'region'                  => 'auto',
                    'endpoint'                => config('services.r2.endpoint'),
                    'use_path_style_endpoint' => true,
                    'credentials'             => [
                        'key'    => config('services.r2.access_key'),
                        'secret' => config('services.r2.secret_key'),
                    ],
                ]);
                if ($heatmapPath) {
                    $cmd = $s3->getCommand('GetObject', ['Bucket' => config('services.r2.bucket'), 'Key' => $heatmapPath]);
                    $reportArr['heatmap_url'] = (string) $s3->createPresignedRequest($cmd, '+24 hours')->getUri();
                }
                if ($segmentationPath) {
                    $cmd = $s3->getCommand('GetObject', ['Bucket' => config('services.r2.bucket'), 'Key' => $segmentationPath]);
                    $reportArr['segmentation_url'] = (string) $s3->createPresignedRequest($cmd, '+24 hours')->getUri();
                }
                if ($patchesPath) {
                    $cmd = $s3->getCommand('GetObject', ['Bucket' => config('services.r2.bucket'), 'Key' => $patchesPath]);
                    $reportArr['patches_url'] = (string) $s3->createPresignedRequest($cmd, '+24 hours')->getUri();
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to presign XAI images for report {$report->id}: {$e->getMessage()}");
            }
        }

        return response()->json($reportArr);
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

        // Send report to doctor via email with PDF attached
        $doctor = auth()->user();
        try {
            $report->load(['patient', 'prediction.aiModel', 'examination', 'prediction.xaiResult']);

            // Generate HTML report content
            $htmlContent = $this->generateReportHtml($report, $doctor);

            // Convert HTML to real PDF using dompdf
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($htmlContent)->setPaper('a4', 'portrait');
            $pdfBytes = $pdf->output();
            $b64Content = base64_encode($pdfBytes);
            $filename = "report-{$report->patient?->patient_identifier}-{$report->id}.pdf";

            \Illuminate\Support\Facades\Mail::to($doctor->email)
                ->send(new \App\Mail\ReportGeneratedMail($report, $doctor, $b64Content, $filename));

            \Illuminate\Support\Facades\Log::info("[Report] PDF email sent to {$doctor->email} for report #{$report->id}");
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("[Report] Email failed for report #{$report->id}: {$e->getMessage()}");
        }

        return response()->json(['message' => 'Report finalized and sent to your email.', 'report' => $report->fresh()]);
    }

    public function generateReportHtml(Report $report, $doctor, array $imageUrls = []): string
    {
        $patient   = $report->patient;
        $pred      = $report->prediction;
        $xai       = $pred?->xaiResult;
        $isLumA    = $pred?->is_lum_a;
        $conf      = $pred?->confidence_lum_a ?? 0;
        $confNon   = $pred?->confidence_non_lum_a ?? (1 - $conf);
        $date      = now()->format('d F Y · H:i');
        $reportNum = str_pad($report->id, 6, '0', STR_PAD_LEFT);
        $primary   = $isLumA ? '#0BB592' : '#F55486';
        $label     = $isLumA ? 'Luminal A' : 'Non-Luminal A';
        $stageRoman = ['I', 'II', 'III', 'IV'][($patient?->stage_num ?? 1) - 1] ?? '—';

        $model         = $pred?->aiModel;
        $thresholdVal  = $model?->threshold ?? 0.43;

        // Therapy recommendations (proper medical detail)
        if ($isLumA) {
            $therapyPrimary    = 'Endocrine (Hormonal) Therapy';
            $therapyPrimaryAr  = 'العلاج الهرموني (الغدد الصماء)';
            
            $therapyAgents     = 'Tamoxifen 20mg/day (pre-menopausal) or Aromatase Inhibitors — Anastrozole/Letrozole (post-menopausal), 5-10 years duration';
            $therapyAgentsAr   = 'تاموكسيفين 20 ملغ/اليوم (قبل انقطاع الطمث) أو مثبطات الأروماتاز — أناستروزول/ليتروزول (بعد انقطاع الطمث)، لمدة 5-10 سنوات';
            
            $therapyRation     = 'Luminal A tumours are strongly hormone receptor-positive (ER/PR+) with low Ki-67 proliferation index. They respond favourably to hormonal blockade. Chemotherapy is generally not indicated unless adverse genomic features are present.';
            $therapyRationAr   = 'أورام لومينال A (Luminal A) تكون إيجابية لمستقبلات الهرمونات بشكل قوي (ER/PR+) مع مؤشر تكاثر منخفض (Ki-67). وهي تستجيب بشكل إيجابي للحصار الهرموني. لا يوصى عادة بالعلاج الكيميائي إلا في حالة وجود خصائص جينومية غير مواتية.';
            
            $therapyPrognos    = 'Favourable prognosis. 5-year overall survival rate exceeds 90% with appropriate endocrine therapy and adjuvant care.';
            $therapyPrognosAr  = 'إنذار إيجابي ومبشر. يتجاوز معدل البقاء على قيد الحياة لمدة 5 سنوات 90% مع العلاج الهرموني المناسب والرعاية الداعمة المرافقة.';
            
            $therapyAdditional   = 'Consider Oncotype DX or MammaPrint genomic assay for borderline cases. Adjuvant radiation per staging guidelines. Annual follow-up imaging recommended.';
            $therapyAdditionalAr = 'النظر في إجراء اختبار جينومي مثل Oncotype DX أو MammaPrint للحالات الحدية. العلاج الإشعاعي المساعد وفقاً لتوجيهات تحديد المرحلة. يُوصى بالتصوير السنوي للمتابعة.';
        } else {
            $therapyPrimary    = 'Chemotherapy ± Targeted Therapy';
            $therapyPrimaryAr  = 'العلاج الكيميائي ± العلاج الموجه';
            
            $therapyAgents     = 'Anthracycline/Taxane-based regimen (AC-T or TC). Add Trastuzumab + Pertuzumab if HER2+. Consider CDK4/6 inhibitors (Palbociclib) if Luminal B. Immunotherapy (Pembrolizumab) if TNBC + PD-L1+.';
            $therapyAgentsAr   = 'نظام يعتمد على الأنثراسيكلين/التاكسان (AC-T أو TC). إضافة تراستوزوماب + بيرتوزوماب إذا كان HER2 إيجابياً. النظر في مثبطات CDK4/6 (بالبوسيكليب) إذا كان لومينال B. العلاج المناعي (بيمبروليزوماب) إذا كان سرطان الثدي ثلاثي السلبية مع إيجابية PD-L1.';
            
            $therapyRation     = 'Non-Luminal A subtypes typically exhibit higher proliferation rates and may lack strong hormone receptor expression. Cytotoxic and targeted approaches are warranted given the more aggressive biological behaviour.';
            $therapyRationAr   = 'تُظهر الأنواع الفرعية غير لومينال A (Non-Luminal A) عادةً معدلات تكاثر أعلى وقد تفتقر إلى التعبير القوي عن مستقبلات الهرمونات. يوصى بالنهج السام للخلايا والموجه نظراً للسلوك البيولوجي الأكثر عدوانية للورم.';
            
            $therapyPrognos    = 'Variable prognosis depending on exact molecular subtype (HER2-enriched, Basal-like, or Luminal B). Multi-disciplinary tumour board (MDT) consultation strongly recommended.';
            $therapyPrognosAr  = 'إنذار متغير يعتمد على النوع الفرعي الجزيئي الدقيق (المخصب بـ HER2، أو الشبيه بالخلايا القاعدية، أو لومينال B). يوصى بشدة باستشارة لجنة الأورام متعددة التخصصات (MDT).';
            
            $therapyAdditional   = 'Evaluate PD-L1 expression for immunotherapy eligibility. Genetic counselling if BRCA1/2 mutation suspected. Sentinel lymph node biopsy for staging.';
            $therapyAdditionalAr = 'تقييم تعبير PD-L1 لأهلية العلاج المناعي. الاستشارة الوراثية في حال الشك في وجود طفرة BRCA1/2. خزعة العقدة الليمفاوية الخافرة لتحديد المرحلة.';
        }

        // Receptor status
        $erStr  = $patient?->er_status  ? 'Positive (+)' : 'Negative (−)';
        $prStr  = $patient?->pr_status  ? 'Positive (+)' : 'Negative (−)';
        $herStr = $patient?->her2_binary ? 'Positive (+)' : 'Negative (−)';

        // XAI data
        $topFeatures = $xai?->top_features ?? [];
        $fusionGate  = $topFeatures['fusion_gate'] ?? null;
        $topPatches  = $topFeatures['top_patches'] ?? [];
        $imgPct      = $fusionGate ? round($fusionGate['image_weight'] * 100) : 50;
        $clinPct     = $fusionGate ? round($fusionGate['clinical_weight'] * 100) : 50;

        // Images: use pre-supplied URLs (for email PDF) or fetch+embed as base64 (for browser print)
        $heatmapBase64 = '';
        $segmentationBase64 = '';
        $patchesBase64 = '';
        $xaiR2Key     = $xai?->heatmap_path;
        $segR2Key     = $xai?->segmentation_path;
        $patchesR2Key = $xai?->patches_path;

        if (!empty($imageUrls)) {
            // Email mode: caller provides presigned URLs — use them directly as <img src>
            $heatmapBase64     = $imageUrls['heatmap']     ?? '';
            $segmentationBase64 = $imageUrls['segmentation'] ?? '';
            $patchesBase64     = $imageUrls['patches']     ?? '';
        } elseif ($xaiR2Key || $segR2Key || $patchesR2Key) {
            // Browser mode: fetch bytes from R2 and embed as data URIs
            try {
                $s3 = new \Aws\S3\S3Client([
                    'version'                 => 'latest',
                    'region'                  => 'auto',
                    'endpoint'                => config('services.r2.endpoint'),
                    'use_path_style_endpoint' => true,
                    'credentials'             => [
                        'key'    => config('services.r2.access_key'),
                        'secret' => config('services.r2.secret_key'),
                    ],
                ]);
                if ($xaiR2Key) {
                    $bytes = $s3->getObject(['Bucket' => config('services.r2.bucket'), 'Key' => $xaiR2Key])['Body']->getContents();
                    $heatmapBase64 = 'data:image/png;base64,' . base64_encode($bytes);
                }
                if ($segR2Key) {
                    $bytes = $s3->getObject(['Bucket' => config('services.r2.bucket'), 'Key' => $segR2Key])['Body']->getContents();
                    $segmentationBase64 = 'data:image/png;base64,' . base64_encode($bytes);
                }
                if ($patchesR2Key) {
                    $bytes = $s3->getObject(['Bucket' => config('services.r2.bucket'), 'Key' => $patchesR2Key])['Body']->getContents();
                    $patchesBase64 = 'data:image/png;base64,' . base64_encode($bytes);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("[Report] Failed to fetch XAI images: {$e->getMessage()}");
            }
        }

        // Build patches table HTML
        $patchesRows = '';
        if (!empty($topPatches)) {
            $sorted = collect($topPatches)->sortByDesc('attention')->take(10)->values();
            foreach ($sorted as $i => $p) {
                $rank = $i + 1;
                $idx = $p['patch_index'] ?? '—';
                $att = number_format(($p['attention'] ?? 0) * 100, 2);
                $patchesRows .= "<tr><td>#{$rank}</td><td>Patch {$idx}</td><td><strong>{$att}%</strong></td></tr>";
            }
        }

        $heatmapSection = $heatmapBase64
            ? "<div class='heatmap-section'><img src='{$heatmapBase64}' alt='Attention heatmap overlay' style='width:100%;max-width:600px;border-radius:8px;border:1px solid #e2e8f0;' /><p class='heatmap-caption'>Continuous attention heatmap overlaid on the slide. Hot colors (yellow/red) indicate regions the model focused on most. Numbered circles mark top-attended patches.</p></div>"
            : "<p style='font-size:12px;color:#94a3b8;font-style:italic;'>Attention heatmap not available for this prediction.</p>";

        $segmentationSection = $segmentationBase64
            ? "<div class='section'><div class='section-title'>Tissue Segmentation Map</div><div class='heatmap-section'><img src='{$segmentationBase64}' alt='Tissue segmentation map' style='width:100%;max-width:600px;border-radius:8px;border:1px solid #e2e8f0;' /><p class='heatmap-caption'>Numbered circles show the top-attended tissue regions on the slide thumbnail. Gold = highest attention.</p></div></div>"
            : "";

        $patchesSection = $patchesBase64
            ? "<div class='section'><div class='section-title'>Top-Attended Histopathology Patches</div><div class='heatmap-section'><img src='{$patchesBase64}' alt='Top attended patches grid' style='width:100%;max-width:600px;border-radius:8px;border:1px solid #e2e8f0;' /><p class='heatmap-caption'>The 20 tissue patches the model attended to most. Each tile shows the actual WSI region with its attention score. Gold border = top-3, orange = top-8.</p></div></div>"
            : ($heatmapBase64
                ? ""
                : "<p style='font-size:12px;color:#94a3b8;font-style:italic;'>Top-attended patches visualization not available for this prediction (clinical-only mode).</p>");

        $patchesTable = $patchesRows
            ? "<table class='patches-table'><thead><tr><th>Rank</th><th>Patch ID</th><th>Attention Score</th></tr></thead><tbody>{$patchesRows}</tbody></table>"
            : "<p style='font-size:12px;color:#94a3b8;font-style:italic;'>No patch attention data available (clinical-only prediction).</p>";

        $clinicalNotes = $report->notes ? "<div class='section'><div class='section-title'>Physician Notes</div><div class='notes-box'>" . e($report->notes) . "</div></div>" : '';

        $orgName = $doctor->organization?->name ?? 'BReCAI Platform';

        return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"/>
<title>EMR · Clinical Report {$reportNum}</title>
<style>
@page { margin: 24px 28px; size: A4; }
* { box-sizing: border-box; }
body { font-family: 'DejaVu Sans', Arial, sans-serif; margin: 0; padding: 0; color: #1e293b; font-size: 11px; line-height: 1.5; }

/* Header */
.report-header { background: linear-gradient(135deg, #093A7A 0%, #0572B2 100%); color: white; padding: 22px 28px; border-radius: 14px; margin-bottom: 22px; }
.report-header .eyebrow { font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 2.5px; opacity: 0.7; margin-bottom: 6px; }
.report-header h1 { margin: 0; font-size: 22px; font-weight: 900; letter-spacing: -0.3px; }
.report-header .meta { display: flex; gap: 18px; margin-top: 12px; font-size: 10px; opacity: 0.85; flex-wrap: wrap; }
.subtype-badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: 1.2px; margin-top: 10px; background: rgba(255,255,255,0.15); border: 1.5px solid rgba(255,255,255,0.3); }

/* Sections */
.section { margin-bottom: 18px; page-break-inside: avoid; }
.section-title { font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: 2.5px; color: #0572B2; margin-bottom: 8px; padding-bottom: 5px; border-bottom: 2px solid #e2e8f0; }

/* Info grid */
.info-grid { display: table; width: 100%; border-spacing: 8px 0; }
.info-row { display: table-row; }
.info-card { display: table-cell; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 9px 11px; vertical-align: top; }
.info-card .lbl { font-size: 8px; font-weight: 900; text-transform: uppercase; letter-spacing: 1.3px; color: #94a3b8; margin-bottom: 2px; }
.info-card .val { font-size: 13px; font-weight: 900; color: #1e293b; }

/* Result box */
.result-box { background: {$primary}15; border: 2px solid {$primary}40; border-radius: 12px; padding: 16px 20px; margin-bottom: 18px; }
.result-flex { display: table; width: 100%; }
.result-main { display: table-cell; vertical-align: middle; }
.result-conf { display: table-cell; vertical-align: middle; text-align: right; width: 100px; }
.result-label-text { font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: 2px; color: #64748b; }
.result-value { font-size: 24px; font-weight: 900; color: {$primary}; margin: 3px 0; }
.result-sub { font-size: 11px; color: #475569; }
.conf-circle { width: 70px; height: 70px; border-radius: 50%; border: 5px solid {$primary}; background: white; text-align: center; line-height: 60px; font-size: 14px; font-weight: 900; color: {$primary}; display: inline-block; }

/* Therapy */
.therapy-box { background: white; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
.therapy-header { background: {$primary}; color: white; padding: 8px 14px; font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: 1.3px; }
.therapy-body { padding: 12px 14px; }
.therapy-row { margin-bottom: 12px; }
.therapy-table-row { width: 100%; border-collapse: collapse; border: none; margin: 0; }
.therapy-table-row td { border: none; padding: 0; vertical-align: top; }
.therapy-col-en { width: 50%; padding-right: 12px; text-align: left; }
.therapy-col-ar { width: 50%; padding-left: 12px; text-align: right; direction: rtl; }
.therapy-row .label { font-size: 8px; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; color: #64748b; margin-bottom: 2px; text-align: left; }
.therapy-row .label-ar { font-size: 10px; font-weight: bold; color: #64748b; margin-bottom: 2px; text-align: right; }
.therapy-row .content { font-size: 10.5px; color: #334155; line-height: 1.5; text-align: left; }
.therapy-row .content-ar { font-size: 10.5px; color: #334155; line-height: 1.5; text-align: right; direction: rtl; }

/* XAI */
.xai-section { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; }
.gate-bar { display: table; width: 100%; height: 22px; border-radius: 6px; overflow: hidden; margin: 8px 0; border-spacing: 0; }
.gate-img { display: table-cell; background: #0572B2; vertical-align: middle; text-align: center; color: white; font-size: 9px; font-weight: 800; }
.gate-clin { display: table-cell; background: #0BB592; vertical-align: middle; text-align: center; color: white; font-size: 9px; font-weight: 800; }
.gate-legend { font-size: 9px; color: #64748b; font-weight: 700; margin-top: 4px; }

/* Heatmap */
.heatmap-section { text-align: center; margin: 12px 0; }
.heatmap-caption { font-size: 10px; color: #64748b; font-style: italic; margin-top: 6px; }

/* Patches table */
.patches-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.patches-table th { background: #f1f5f9; padding: 6px 10px; text-align: left; font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; color: #64748b; border-bottom: 2px solid #e2e8f0; }
.patches-table td { padding: 6px 10px; font-size: 11px; border-bottom: 1px solid #e2e8f0; }

/* Notes */
.notes-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 10px 12px; font-size: 11px; color: #92400e; line-height: 1.6; }

/* Disclaimer */
.disclaimer { margin-top: 18px; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; font-size: 9px; color: #64748b; line-height: 1.55; }
.disclaimer strong { color: #475569; }

/* Footer */
.report-footer { margin-top: 20px; padding-top: 14px; border-top: 2px solid #e2e8f0; font-size: 9px; color: #94a3b8; }
.sig-line { border-top: 1.5px solid #1e293b; width: 200px; margin-top: 24px; padding-top: 5px; font-size: 11px; font-weight: 800; color: #1e293b; text-align: center; }
</style></head><body>

<!-- Header -->
<div class="report-header">
  <div class="eyebrow">BReCAI-FED · Electronic Medical Record · Molecular Subtype Classification</div>
  <h1>Patient {$patient?->patient_identifier}</h1>
  <div class="meta">
    <span><strong>EMR #</strong> {$reportNum}</span>
    <span><strong>Issued</strong> {$date}</span>
    <span><strong>Physician</strong> Dr. {$doctor->name}</span>
    <span><strong>Institution</strong> {$orgName}</span>
  </div>
  <span class="subtype-badge">{$label} · Confidence {$this->pct($conf)}%</span>
</div>

<!-- Patient & Clinical Profile -->
<div class="section">
  <div class="section-title">Patient & Clinical Profile</div>
  <div class="info-grid">
    <div class="info-row">
      <div class="info-card"><div class="lbl">Patient ID</div><div class="val">{$patient?->patient_identifier}</div></div>
      <div class="info-card"><div class="lbl">Age</div><div class="val">{$patient?->age} years</div></div>
      <div class="info-card"><div class="lbl">Tumour Stage</div><div class="val">Stage {$stageRoman}</div></div>
    </div>
  </div>
  <div class="info-grid" style="margin-top:8px;">
    <div class="info-row">
      <div class="info-card"><div class="lbl">Estrogen Receptor (ER)</div><div class="val">{$erStr}</div></div>
      <div class="info-card"><div class="lbl">Progesterone Receptor (PR)</div><div class="val">{$prStr}</div></div>
      <div class="info-card"><div class="lbl">HER2/neu</div><div class="val">{$herStr}</div></div>
    </div>
  </div>
</div>

<!-- AI Result -->
<div class="result-box">
  <div class="result-flex">
    <div class="result-main">
      <div class="result-label-text">AI Classification Result</div>
      <div class="result-value">{$label}</div>
      <div class="result-sub">Probability LumA: <strong>{$this->pct($conf)}%</strong> · Probability Non-LumA: <strong>{$this->pct($confNon)}%</strong> · Threshold: {$this->pct($thresholdVal)}%</div>
      <div class="result-sub" style="margin-top:4px;">Model: <strong>A6 Cross-Attention Fusion (CONCH ViT-B/16 + Clinical Encoder)</strong></div>
    </div>
    <div class="result-conf">
      <div class="conf-circle">{$this->pct($conf)}%</div>
    </div>
  </div>
</div>

<!-- Therapy Recommendation -->
<div class="section">
  <div class="section-title">Recommended Therapeutic Strategy / الخطة العلاجية الموصى بها</div>
  <div class="therapy-box">
    <div class="therapy-header">
      <table style="width:100%; border-collapse:collapse; border:none; margin:0; padding:0;">
        <tr>
          <td style="width:50%; text-align:left; color:white; font-size:10px; font-weight:900; border:none;">Treatment Plan</td>
          <td style="width:50%; text-align:right; color:white; font-size:10px; font-weight:900; border:none; direction:rtl;">خطة العلاج</td>
        </tr>
      </table>
    </div>
    <div class="therapy-body">
      <!-- Primary Modality -->
      <div class="therapy-row">
        <table class="therapy-table-row">
          <tr>
            <td class="therapy-col-en">
              <div class="label">Primary Modality</div>
              <div class="content"><strong>{$therapyPrimary}</strong></div>
            </td>
            <td class="therapy-col-ar">
              <div class="label-ar">طريقة العلاج الأساسية</div>
              <div class="content-ar"><strong>{$therapyPrimaryAr}</strong></div>
            </td>
          </tr>
        </table>
      </div>

      <!-- Pharmacological Agents -->
      <div class="therapy-row">
        <table class="therapy-table-row">
          <tr>
            <td class="therapy-col-en">
              <div class="label">Pharmacological Agents</div>
              <div class="content">{$therapyAgents}</div>
            </td>
            <td class="therapy-col-ar">
              <div class="label-ar">الأدوية والعوامل العلاجية</div>
              <div class="content-ar">{$therapyAgentsAr}</div>
            </td>
          </tr>
        </table>
      </div>

      <!-- Clinical Rationale -->
      <div class="therapy-row">
        <table class="therapy-table-row">
          <tr>
            <td class="therapy-col-en">
              <div class="label">Clinical Rationale</div>
              <div class="content">{$therapyRation}</div>
            </td>
            <td class="therapy-col-ar">
              <div class="label-ar">المبرر السريري</div>
              <div class="content-ar">{$therapyRationAr}</div>
            </td>
          </tr>
        </table>
      </div>

      <!-- Prognosis -->
      <div class="therapy-row">
        <table class="therapy-table-row">
          <tr>
            <td class="therapy-col-en">
              <div class="label">Prognosis</div>
              <div class="content">{$therapyPrognos}</div>
            </td>
            <td class="therapy-col-ar">
              <div class="label-ar">التنبؤ بسير المرض / الإنذار</div>
              <div class="content-ar">{$therapyPrognosAr}</div>
            </td>
          </tr>
        </table>
      </div>

      <!-- Additional Considerations -->
      <div class="therapy-row">
        <table class="therapy-table-row">
          <tr>
            <td class="therapy-col-en">
              <div class="label">Additional Considerations</div>
              <div class="content">{$therapyAdditional}</div>
            </td>
            <td class="therapy-col-ar">
              <div class="label-ar">اعتبارات إضافية</div>
              <div class="content-ar">{$therapyAdditionalAr}</div>
            </td>
          </tr>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- XAI Explainability -->
<div class="section">
  <div class="section-title">Explainability & Model Interpretation (XAI)</div>
  <div class="xai-section">
    <div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;color:#64748b;margin-bottom:6px;">Modality Contribution Gate</div>
    <div class="gate-bar">
      <div class="gate-img" style="width:{$imgPct}%">Histopathology {$imgPct}%</div>
      <div class="gate-clin" style="width:{$clinPct}%">Clinical {$clinPct}%</div>
    </div>
    <div class="gate-legend">Gate weights show how the model balanced histopathology image features (CONCH) vs clinical biomarkers (ER/PR/HER2/Stage/Age) to reach this classification.</div>
  </div>
</div>

<!-- Attention Heatmap -->
<div class="section">
  <div class="section-title">Attention Heatmap</div>
  {$heatmapSection}
</div>

<!-- Tissue Segmentation Map -->
{$segmentationSection}

<!-- Top Histopathology Patches Grid -->
{$patchesSection}

{$clinicalNotes}

<!-- Disclaimer -->
<div class="disclaimer">
  <strong>Legal Disclaimer:</strong> This AI-generated report is intended as a <strong>diagnostic aid</strong> and must be reviewed by a licensed medical professional before clinical action. The BReCAI system provides molecular subtype classification to assist in treatment planning — it does not replace professional medical judgement. All therapeutic recommendations require validation by a multi-disciplinary oncology team.
</div>

<!-- Signature -->
<div class="sig-line">Dr. {$doctor->name}</div>

<!-- Footer -->
<div class="report-footer">
  <strong>BReCAI-FED</strong> · Federated Medical AI Platform · EMR #{$reportNum} · Status: <strong>{$this->statusLabel($report->status)}</strong> · Generated on {$date}
</div>

</body></html>
HTML;
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'final', 'finalized' => 'FINALIZED',
            'draft' => 'DRAFT',
            default => strtoupper($status),
        };
    }

    public function bioStr($patient): string
    {
        if (!$patient) return '—';
        $er  = $patient->er_status  ? 'ER+' : 'ER-';
        $pr  = $patient->pr_status  ? 'PR+' : 'PR-';
        $her = $patient->her2_binary ? 'HER2+' : 'HER2-';
        return "{$er} / {$pr} / {$her}";
    }

    public function pct($val): string
    {
        return number_format(($val ?? 0) * 100, 1);
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
