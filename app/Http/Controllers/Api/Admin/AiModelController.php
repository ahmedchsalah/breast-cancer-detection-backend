<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AiModelController extends Controller
{
    /**
     * List all AI models.
     */
    public function index(): JsonResponse
    {
        $models = AiModel::withCount('predictions', 'flRounds')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($models);
    }

    /**
     * Show a single model with its FL round history.
     */
    public function show(AiModel $aiModel): JsonResponse
    {
        $aiModel->load(['flRounds' => fn($q) => $q->orderBy('round_number')])
            ->loadCount('predictions');

        return response()->json($aiModel);
    }

    /**
     * Register a new AI model record.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:100',
            'version'   => 'required|string|max:30',
            'file_path' => 'required|string|max:500', // Path where model file is stored
            'metadata'  => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $model = AiModel::create($validated);

        return response()->json($model, 201);
    }

    /**
     * Update model metadata.
     */
    public function update(Request $request, AiModel $aiModel): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'sometimes|string|max:100',
            'version'   => 'sometimes|string|max:30',
            'file_path' => 'sometimes|string|max:500',
            'metadata'  => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $aiModel->update($validated);

        return response()->json($aiModel->fresh());
    }

    /**
     * Delete a model. Prevents deletion if it has completed predictions.
     */
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

    /**
     * Activate a model (only one active model at a time is recommended).
     */
    public function activate(AiModel $aiModel): JsonResponse
    {
        // Deactivate all others first for clean state
        AiModel::where('id', '!=', $aiModel->id)->update(['is_active' => false]);
        $aiModel->update(['is_active' => true]);

        return response()->json(['message' => "Model '{$aiModel->name} v{$aiModel->version}' is now the active model."]);
    }

    /**
     * Deactivate a model.
     */
    public function deactivate(AiModel $aiModel): JsonResponse
    {
        $aiModel->update(['is_active' => false]);

        return response()->json(['message' => "Model '{$aiModel->name}' deactivated."]);
    }
}
