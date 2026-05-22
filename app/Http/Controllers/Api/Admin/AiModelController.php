<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

// ============================================================
//  Shared Schemas — Admin
// ============================================================

#[OA\Schema(
    schema: "AiModelObject",
    type: "object",
    properties: [
        new OA\Property(property: "id",          type: "integer", example: 1),
        new OA\Property(property: "name",        type: "string",  example: "BrCa-LumA-v3"),
        new OA\Property(property: "version",     type: "string",  example: "3.0.1"),
        new OA\Property(property: "file_path",   type: "string",  example: "models/brca_v3.pt"),
        new OA\Property(property: "is_active",   type: "boolean", example: true),
        new OA\Property(property: "metadata",    type: "object",  nullable: true),
        new OA\Property(property: "created_at",  type: "string",  format: "date-time"),
    ]
)]

class AiModelController extends Controller
{
    // ============================================================
    //  INDEX (Public — for org managers, read-only active models)
    // ============================================================

    public function indexPublic(): JsonResponse
    {
        $models = AiModel::where('is_active', true)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($models);
    }

    // ============================================================
    //  INDEX
    // ============================================================

    #[OA\Get(
        path: "/admin/ai-models",
        tags: ["Admin — AI Models"],
        summary: "List all AI models",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "List of models",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(ref: "#/components/schemas/AiModelObject")
                )
            ),
        ]
    )]
    public function index(): JsonResponse
    {
        $models = AiModel::withCount('predictions', 'flRounds')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($models);
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/admin/ai-models/{id}",
        tags: ["Admin — AI Models"],
        summary: "Show a single AI model with FL round history",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Model details", content: new OA\JsonContent(ref: "#/components/schemas/AiModelObject")),
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
        path: "/admin/ai-models",
        tags: ["Admin — AI Models"],
        summary: "Register a new AI model record",
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "version", "file_path"],
                properties: [
                    new OA\Property(property: "name",      type: "string",  example: "BrCa-LumA-v3"),
                    new OA\Property(property: "version",   type: "string",  example: "3.0.1"),
                    new OA\Property(property: "file_path", type: "string",  example: "models/brca_v3.pt"),
                    new OA\Property(property: "metadata",  type: "object",  nullable: true),
                    new OA\Property(property: "is_active", type: "boolean", example: false),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Model created", content: new OA\JsonContent(ref: "#/components/schemas/AiModelObject")),
            new OA\Response(response: 422, description: "Validation error", content: new OA\JsonContent(ref: "#/components/schemas/ValidationError")),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:100',
            'slug'           => 'required|string|max:100|unique:ai_models,slug',
            'version'        => 'required|string|max:30',
            'inference_type' => 'required|in:a6_fusion,a4_image_only,clinical_only',
            'description'    => 'nullable|string',
            'auc'            => 'nullable|numeric|between:0,1',
            'accuracy'       => 'nullable|numeric|between:0,1',
            'sensitivity'    => 'nullable|numeric|between:0,1',
            'specificity'    => 'nullable|numeric|between:0,1',
            'f1_score'       => 'nullable|numeric|between:0,1',
            'threshold'      => 'nullable|numeric|between:0,1',
            'metadata'       => 'nullable|array',
            'is_active'      => 'nullable|boolean',
        ]);

        $model = AiModel::create($validated);

        return response()->json($model, 201);
    }

    // ============================================================
    //  UPDATE
    // ============================================================

    #[OA\Put(
        path: "/admin/ai-models/{id}",
        tags: ["Admin — AI Models"],
        summary: "Update model metadata",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "name",      type: "string"),
                    new OA\Property(property: "version",   type: "string"),
                    new OA\Property(property: "file_path", type: "string"),
                    new OA\Property(property: "metadata",  type: "object", nullable: true),
                    new OA\Property(property: "is_active", type: "boolean"),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Updated model", content: new OA\JsonContent(ref: "#/components/schemas/AiModelObject")),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function update(Request $request, AiModel $aiModel): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'sometimes|string|max:100',
            'slug'           => 'sometimes|string|max:100|unique:ai_models,slug,' . $aiModel->id,
            'version'        => 'sometimes|string|max:30',
            'inference_type' => 'sometimes|in:a6_fusion,a4_image_only,clinical_only',
            'description'    => 'nullable|string',
            'auc'            => 'nullable|numeric|between:0,1',
            'accuracy'       => 'nullable|numeric|between:0,1',
            'sensitivity'    => 'nullable|numeric|between:0,1',
            'specificity'    => 'nullable|numeric|between:0,1',
            'f1_score'       => 'nullable|numeric|between:0,1',
            'threshold'      => 'nullable|numeric|between:0,1',
            'metadata'       => 'nullable|array',
            'is_active'      => 'nullable|boolean',
        ]);

        $aiModel->update($validated);

        return response()->json($aiModel->fresh());
    }

    // ============================================================
    //  DESTROY
    // ============================================================

    #[OA\Delete(
        path: "/admin/ai-models/{id}",
        tags: ["Admin — AI Models"],
        summary: "Delete a model (blocked if it has completed predictions)",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Deleted"),
            new OA\Response(response: 422, description: "Cannot delete — has completed predictions"),
        ]
    )]
    public function destroy(AiModel $aiModel): JsonResponse
    {
        if ($aiModel->predictions()->where('status', 'completed')->exists()) {
            return response()->json([
                'message' => 'Cannot delete a model that has been used for completed predictions. Deactivate it instead.',
            ], 422);
        }

        $aiModel->delete();

        return response()->json(['message' => 'AI model deleted.']);
    }

    // ============================================================
    //  ACTIVATE
    // ============================================================

    #[OA\Post(
        path: "/admin/ai-models/{id}/activate",
        tags: ["Admin — AI Models"],
        summary: "Activate a model and deactivate all others",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Model activated"),
        ]
    )]
    public function activate(AiModel $aiModel): JsonResponse
    {
        AiModel::where('id', '!=', $aiModel->id)->update(['is_active' => false]);
        $aiModel->update(['is_active' => true]);

        return response()->json(['message' => "Model '{$aiModel->name} v{$aiModel->version}' is now the active model."]);
    }

    // ============================================================
    //  DEACTIVATE
    // ============================================================

    #[OA\Post(
        path: "/admin/ai-models/{id}/deactivate",
        tags: ["Admin — AI Models"],
        summary: "Deactivate a model",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Model deactivated"),
        ]
    )]
    public function deactivate(AiModel $aiModel): JsonResponse
    {
        $aiModel->update(['is_active' => false]);

        return response()->json(['message' => "Model '{$aiModel->name}' deactivated."]);
    }
}
