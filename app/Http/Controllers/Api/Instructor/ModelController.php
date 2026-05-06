<?php

namespace App\Http\Controllers\Api\Instructor;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\FlRound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModelController extends Controller
{
    /**
     * List all AI models.
     */
    public function index(): JsonResponse
    {
        $models = AiModel::withCount('flRounds', 'predictions')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($models);
    }

    /**
     * Show a specific AI model with its FL round history.
     */
    public function show(AiModel $aiModel): JsonResponse
    {
        $aiModel->load(['flRounds' => fn($q) => $q->orderBy('round_number')])
            ->loadCount('predictions');

        return response()->json($aiModel);
    }

    /**
     * Register a new model version.
     */
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

    /**
     * Update model metadata/version info.
     */
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
