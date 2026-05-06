<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Models\Prediction;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    private function doctor()
    {
        return auth()->user();
    }

    /**
     * List reports authored by this doctor.
     */
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

    /**
     * Show a single report with full context.
     */
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

    /**
     * Create a draft report for a concluded examination.
     */
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

    /**
     * Update report notes.
     */
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

    /**
     * Finalize a report (marks it as official — no further edits allowed).
     */
    public function finalize(Report $report): JsonResponse
    {
        $this->ensureOwnership($report);

        if ($report->status === Report::STATUS_FINAL) {
            return response()->json(['message' => 'Report is already finalized.'], 422);
        }

        $report->update(['status' => Report::STATUS_FINAL]);

        return response()->json(['message' => 'Report finalized.', 'report' => $report->fresh()]);
    }

    /**
     * Attach a generated PDF file to the report.
     */
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

    /**
     * Delete a draft report.
     */
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
