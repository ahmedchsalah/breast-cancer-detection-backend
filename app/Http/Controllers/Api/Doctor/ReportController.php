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

        // Send report to doctor via email
        $doctor = auth()->user();
        try {
            $report->load(['patient', 'prediction', 'examination']);

            // Generate HTML report content (same as frontend generates)
            $htmlContent = $this->generateReportHtml($report, $doctor);
            $b64Content  = base64_encode($htmlContent);
            $filename    = "report-{$report->patient?->patient_identifier}-{$report->id}.html";

            \Illuminate\Support\Facades\Mail::to($doctor->email)
                ->send(new \App\Mail\ReportGeneratedMail($report, $doctor, $b64Content, $filename));

            \Illuminate\Support\Facades\Log::info("[Report] Email sent to {$doctor->email} for report #{$report->id}");
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("[Report] Email failed for report #{$report->id}: {$e->getMessage()}");
            // Don't fail the finalization if email fails
        }

        return response()->json(['message' => 'Report finalized and sent to your email.', 'report' => $report->fresh()]);
    }

    private function generateReportHtml(Report $report, $doctor): string
    {
        $patient   = $report->patient;
        $pred      = $report->prediction;
        $isLumA    = $pred?->is_lum_a;
        $conf      = $pred?->confidence_lum_a ?? 0;
        $date      = now()->format('d F Y');
        $color     = $isLumA ? '#0BB592' : '#F55486';
        $label     = $isLumA ? 'Luminal A' : 'Non-Luminal A';
        $therapy   = $isLumA
            ? 'Luminal A subtype confirmed. Strong candidate for Endocrine (Hormonal) Therapy — Tamoxifen / Aromatase Inhibitors. Chemotherapy likely not indicated.'
            : 'Non-Luminal A subtype detected. Higher risk profile — Chemotherapy or Targeted Therapy may be required. Consult MDT board.';

        return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"/>
<title>Clinical Report — {$patient?->patient_identifier}</title>
<style>
body{font-family:'Segoe UI',Arial,sans-serif;margin:0;padding:40px;color:#1e293b;}
.header{background:linear-gradient(135deg,#072a5e,#0572B2);color:#fff;padding:32px 40px;border-radius:12px;margin-bottom:32px;}
.header h1{margin:0 0 4px;font-size:28px;font-weight:900;}
.section{margin-bottom:24px;}
.section-title{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:2px;color:#94a3b8;margin-bottom:12px;}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;}
.card-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:4px;}
.card-value{font-size:18px;font-weight:900;color:#1e293b;}
.result-box{background:{$color}15;border:2px solid {$color}40;border-radius:12px;padding:20px 24px;margin-bottom:24px;}
.result-label{font-size:32px;font-weight:900;color:{$color};}
.therapy{background:#f8fafc;border-left:4px solid {$color};padding:14px 16px;border-radius:0 8px 8px 0;font-size:13px;line-height:1.6;}
.footer{margin-top:40px;padding-top:20px;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;}
</style></head><body>
<div class="header">
<p style="font-size:11px;font-weight:800;letter-spacing:3px;text-transform:uppercase;opacity:0.65;margin-bottom:8px;">BRECAI-FED · Clinical Diagnostic Report</p>
<h1>{$patient?->patient_identifier}</h1>
<p>Generated: {$date} · Dr. {$doctor->name}</p>
</div>
<div class="section">
<div class="section-title">Patient Information</div>
<div class="grid">
<div class="card"><div class="card-label">Patient ID</div><div class="card-value">{$patient?->patient_identifier}</div></div>
<div class="card"><div class="card-label">Age</div><div class="card-value">{$patient?->age} years</div></div>
<div class="card"><div class="card-label">Stage</div><div class="card-value">Stage {$patient?->stage_num}</div></div>
<div class="card"><div class="card-label">Biomarkers</div><div class="card-value">
{$this->bioStr($patient)}
</div></div>
</div></div>
<div class="result-box">
<div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:#94a3b8;margin-bottom:8px;">AI Prediction Result</div>
<div class="result-label">{$label}</div>
<p style="margin:8px 0 0;font-size:13px;color:#475569;">Luminal A probability: <strong>{$this->pct($conf)}%</strong></p>
</div>
<div class="section">
<div class="section-title">Therapy Recommendation</div>
<div class="therapy">{$therapy}</div>
</div>
HTML . ($report->notes ? "<div class='section'><div class='section-title'>Clinical Notes</div><div class='therapy'>{$report->notes}</div></div>" : '')
. "<div class='footer'>BRECAI-FED · Federated Medical AI Platform · Report #{$report->id} · Status: " . strtoupper($report->status) . "</div></body></html>";
    }

    private function bioStr($patient): string
    {
        if (!$patient) return '—';
        $er  = $patient->er_status  ? 'ER+' : 'ER-';
        $pr  = $patient->pr_status  ? 'PR+' : 'PR-';
        $her = $patient->her2_binary ? 'HER2+' : 'HER2-';
        return "{$er} / {$pr} / {$her}";
    }

    private function pct($val): string
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
