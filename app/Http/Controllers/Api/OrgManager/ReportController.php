<?php

namespace App\Http\Controllers\Api\OrgManager;

use App\Http\Controllers\Controller;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ReportController extends Controller
{
    private function orgId(): int
    {
        return auth()->user()->organization_id;
    }

    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/org-manager/reports",
        tags: ["OrgManager — Reports"],
        summary: "List all reports generated within this organization",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "status", in: "query", required: false, schema: new OA\Schema(type: "string", enum: ["draft", "final"])),
            new OA\Parameter(name: "doctor_id", in: "query", required: false, schema: new OA\Schema(type: "integer")),
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
            'status'    => 'nullable|in:draft,final',
            'doctor_id' => 'nullable|integer|exists:users,id',
        ]);

        $query = Report::where('organization_id', $this->orgId())
            ->with('patient:id,patient_identifier', 'doctor:id,name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/org-manager/reports/{id}",
        tags: ["OrgManager — Reports"],
        summary: "Show a single report with prediction details",
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
        abort_if($report->organization_id !== $this->orgId(), 403, 'This report does not belong to your organization.');

        $report->load([
            'patient:id,patient_identifier,age,er_status,pr_status,her2_binary',
            'doctor:id,name,email',
            'examination:id,chief_complaint,status,examined_at',
            'prediction:id,is_lum_a,confidence_lum_a,confidence_non_lum_a,status',
        ]);

        return response()->json($report);
    }
}
