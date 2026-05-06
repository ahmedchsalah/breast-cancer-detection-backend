<?php

namespace App\Http\Controllers\Api\Instructor;

use App\Http\Controllers\Controller;
use App\Models\FlContribution;
use App\Models\FlRound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "FlContributionObject",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer"),
        new OA\Property(property: "fl_round_id", type: "integer"),
        new OA\Property(property: "organization_id", type: "integer"),
        new OA\Property(property: "local_sample_size", type: "integer"),
        new OA\Property(property: "local_accuracy_before", type: "number", format: "float"),
        new OA\Property(property: "local_accuracy_after", type: "number", format: "float"),
        new OA\Property(property: "weights_update_path", type: "string"),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
    ]
)]
class ContributionController extends Controller
{
    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/instructor/contributions",
        tags: ["Instructor — Contributions"],
        summary: "List all contributions for a given FL round",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "fl_round_id", in: "query", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "List of contributions",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(ref: "#/components/schemas/FlContributionObject")
                )
            ),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'fl_round_id' => 'required|integer|exists:fl_rounds,id',
        ]);

        $contributions = FlContribution::where('fl_round_id', $request->fl_round_id)
            ->with('organization:id,name,type', 'flRound:id,round_number,status')
            ->get();

        return response()->json($contributions);
    }

    // ============================================================
    //  STORE
    // ============================================================

    #[OA\Post(
        path: "/instructor/contributions",
        tags: ["Instructor — Contributions"],
        summary: "Record a contribution from an organization for a round",
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: [
                    "fl_round_id",
                    "organization_id",
                    "local_sample_size",
                    "local_accuracy_before",
                    "local_accuracy_after",
                    "weights_update_path"
                ],
                properties: [
                    new OA\Property(property: "fl_round_id", type: "integer"),
                    new OA\Property(property: "organization_id", type: "integer"),
                    new OA\Property(property: "local_sample_size", type: "integer"),
                    new OA\Property(property: "local_accuracy_before", type: "number", format: "float"),
                    new OA\Property(property: "local_accuracy_after", type: "number", format: "float"),
                    new OA\Property(property: "weights_update_path", type: "string"),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Contribution recorded", content: new OA\JsonContent(ref: "#/components/schemas/FlContributionObject")),
            new OA\Response(response: 422, description: "Validation error / Duplicate contribution"),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fl_round_id'          => 'required|integer|exists:fl_rounds,id',
            'organization_id'      => 'required|integer|exists:organizations,id',
            'local_sample_size'    => 'required|integer|min:1',
            'local_accuracy_before'=> 'required|numeric|between:0,1',
            'local_accuracy_after' => 'required|numeric|between:0,1',
            'weights_update_path'  => 'required|string|max:500',
        ]);

        // Prevent duplicate contribution for same org in same round
        if (FlContribution::where('fl_round_id', $validated['fl_round_id'])
            ->where('organization_id', $validated['organization_id'])
            ->exists()) {
            return response()->json(['message' => 'This organization has already contributed to this round.'], 422);
        }

        $contribution = FlContribution::create($validated);

        return response()->json($contribution->load('organization:id,name'), 201);
    }
}
