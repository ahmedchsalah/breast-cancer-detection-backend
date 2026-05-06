<?php

namespace App\Http\Controllers\Api\Instructor;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\FlRound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ModelController extends Controller
{
    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/instructor/models",
        tags: ["Instructor — Models"],
        summary: "List all AI models",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "List of AI models",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(ref: "#/components/schemas/AiModelObject")
                )
            )
        ]
    )]
    public function index(): JsonResponse
    {
        $models = AiModel::withCount('flRounds', 'predictions')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($models);
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/instructor/models/{id}",
        tags: ["Instructor — Models"],
        summary: "Show a specific AI model with its FL round history",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "AI model details",
                content: new OA\JsonContent(ref: "#/components/schemas/AiModelObject")
            ),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show(AiModel $aiModel): JsonResponse
    {
        $aiModel->load(['flRounds' => fn($q) => $q->orderBy('round_number')])
            ->loadCount('predictions');

        return response()->json($aiModel);
    }

    // ============================================================
    //  STORE
    // ============================================================

    #[OA\Post(
        path: "/instructor/models",
        tags: ["Instructor — Models"],
        summary: "Register a new model version",
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "version", "file_path"],
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "version", type: "string"),
                    new OA\Property(property: "file_path", type: "string"),
                    new OA\Property(property: "metadata", type: "object", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Model created", content: new OA\JsonContent(ref: "#/components/schemas/AiModelObject")),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:100',
            'version'   => 'required|string|max:30',
            'file_path' => 'required|string|max:500',
            'metadata'  => 'nullable|array',
        ]);

        $model = AiModel::create($validated + ['is_active' => false]);

        return response()->json($model, 201);
    }

    // ============================================================
    //  UPDATE
    // ============================================================

    #[OA\Put(
        path: "/instructor/models/{id}",
        tags: ["Instructor — Models"],
        summary: "Update model metadata/version info",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "version", type: "string"),
                    new OA\Property(property: "file_path", type: "string"),
                    new OA\Property(property: "metadata", type: "object", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Model updated", content: new OA\JsonContent(ref: "#/components/schemas/AiModelObject")),
            new OA\Response(response: 404, description: "Not found"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function update(Request $request, AiModel $aiModel): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'sometimes|string|max:100',
            'version'   => 'sometimes|string|max:30',
            'file_path' => 'sometimes|string|max:500',
            'metadata'  => 'nullable|array',
        ]);

        $aiModel->update($validated);

        return response()->json($aiModel->fresh());
    }
}
