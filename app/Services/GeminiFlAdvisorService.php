<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GeminiFlAdvisorService — Gemini-powered smart hyperparameter selection for FL training.
 *
 * Uses Google Gemini API to recommend optimal hyperparameters based on:
 *   - Dataset characteristics (size, modality)
 *   - Current FL round number and progress
 *   - Previous round performance metrics
 *   - Available compute resources
 *
 * This prevents instructors from making poor hyperparameter choices
 * that could degrade the global model.
 *
 * Falls back to heuristic defaults if Gemini is unavailable.
 */
class GeminiFlAdvisorService
{
    private string $apiKey;
    private string $model;
    private string $aggregationSpaceUrl;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY', ''));
        $this->model = config('services.gemini.model', 'gemini-2.0-flash');
        $this->aggregationSpaceUrl = config('services.fl_aggregation.url', env('FL_AGGREGATION_URL', ''));
    }

    /**
     * Get smart hyperparameter recommendations.
     *
     * First tries the FL aggregation space (which has Gemini integrated),
     * then falls back to direct Gemini API call,
     * then falls back to heuristic defaults.
     */
    public function suggestHyperparameters(array $context): array
    {
        // Try the FL aggregation space first (it has the full Gemini integration)
        if ($this->aggregationSpaceUrl) {
            try {
                $response = Http::timeout(30)->post(
                    rtrim($this->aggregationSpaceUrl, '/') . '/hyperparams/suggest',
                    [
                        'modality'           => $context['modality'] ?? 'FULL',
                        'dataset_size'       => $context['dataset_size'] ?? 50,
                        'current_round'      => $context['current_round'] ?? 1,
                        'previous_accuracy'  => $context['previous_accuracy'] ?? null,
                        'previous_loss'      => $context['previous_loss'] ?? null,
                        'model_type'         => $context['model_type'] ?? 'a6_fusion',
                        'gpu_available'      => $context['gpu_available'] ?? false,
                    ]
                );

                if ($response->successful()) {
                    $data = $response->json();
                    Log::info('[Gemini] Got hyperparams from FL aggregation space', $data);
                    return $data;
                }
            } catch (\Throwable $e) {
                Log::warning('[Gemini] FL aggregation space unavailable, trying direct API: ' . $e->getMessage());
            }
        }

        // Try direct Gemini API call
        if ($this->apiKey) {
            try {
                $result = $this->callGeminiApi($context);
                if ($result) {
                    return $result;
                }
            } catch (\Throwable $e) {
                Log::warning('[Gemini] Direct API failed, using heuristics: ' . $e->getMessage());
            }
        }

        // Fallback: heuristic-based recommendations
        return $this->heuristicHyperparams($context);
    }

    /**
     * Call the Gemini API directly.
     */
    private function callGeminiApi(array $context): ?array
    {
        $prompt = $this->buildPrompt($context);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}",
            [
                'system_instruction' => [
                    'parts' => [[
                        'text' => $this->getSystemPrompt(),
                    ]],
                ],
                'contents' => [
                    'parts' => [['text' => $prompt]],
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 1024,
                    'responseMimeType' => 'application/json',
                ],
            ]
        );

        if (!$response->successful()) {
            Log::warning('[Gemini] API call failed: HTTP ' . $response->status());
            return null;
        }

        $data = $response->json();
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!$text) {
            return null;
        }

        $hyperparams = json_decode($text, true);
        if (!$hyperparams) {
            return null;
        }

        return [
            'learning_rate'  => floatval($hyperparams['learning_rate'] ?? 1e-4),
            'weight_decay'   => floatval($hyperparams['weight_decay'] ?? 1e-4),
            'batch_size'     => intval($hyperparams['batch_size'] ?? 16),
            'local_epochs'   => intval($hyperparams['local_epochs'] ?? 5),
            'dropout_rate'   => floatval($hyperparams['dropout_rate'] ?? 0.55),
            'optimizer'      => $hyperparams['optimizer'] ?? 'AdamW',
            'scheduler'      => $hyperparams['scheduler'] ?? null,
            'warmup_steps'   => $hyperparams['warmup_steps'] ?? null,
            'reasoning'      => $hyperparams['reasoning'] ?? 'Gemini recommendation',
        ];
    }

    private function getSystemPrompt(): string
    {
        return "You are an expert machine learning engineer specializing in federated learning for medical imaging (breast cancer classification). Your task is to recommend optimal hyperparameters for a local FL training round.\n\n"
            . "Context about the model architecture:\n"
            . "- BReCAI v12 uses Cross-Attention Fusion (A6) combining CONCH ViT-B/16 image features with clinical data\n"
            . "- Architecture: HIDDEN=128, N_HEADS=4, ATT_DIM=128, CLIN_OUT=64, DROP_RATE=0.55, CONCH_DIM=512\n"
            . "- The model classifies breast cancer as Luminal-A vs Non-Luminal-A\n"
            . "- Training uses Gated Attention Pooling with TTA\n\n"
            . "Key FL considerations:\n"
            . "- Early rounds need higher learning rates for exploration\n"
            . "- Later rounds need lower learning rates for fine-tuning convergence\n"
            . "- Small datasets need regularization (higher dropout, weight decay)\n"
            . "- FULL mode (with genomics) can handle slightly higher complexity\n"
            . "- DZ mode (no genomics) should be more conservative\n"
            . "- FedAvg works best when local epochs are moderate (3-8) to avoid client drift\n\n"
            . "You MUST respond with valid JSON only, no markdown, no explanation outside JSON:\n"
            . '{"learning_rate": <float>, "weight_decay": <float>, "batch_size": <int>, "local_epochs": <int>, "dropout_rate": <float>, "optimizer": "<string>", "scheduler": "<string or null>", "warmup_steps": <int or null>, "reasoning": "<brief explanation>"}';
    }

    private function buildPrompt(array $context): string
    {
        return "Recommend hyperparameters for this FL training round:\n"
            . "- Modality: " . ($context['modality'] ?? 'FULL') . "\n"
            . "- Dataset size: " . ($context['dataset_size'] ?? 50) . " samples\n"
            . "- Current FL round: " . ($context['current_round'] ?? 1) . "\n"
            . "- Previous accuracy: " . ($context['previous_accuracy'] ?? 'N/A') . "\n"
            . "- Previous loss: " . ($context['previous_loss'] ?? 'N/A') . "\n"
            . "- Model type: " . ($context['model_type'] ?? 'a6_fusion') . "\n"
            . "- GPU available: " . ($context['gpu_available'] ?? false) . "\n\n"
            . "Respond with JSON only.";
    }

    /**
     * Fallback heuristic hyperparameter selection.
     */
    private function heuristicHyperparams(array $context): array
    {
        $round = $context['current_round'] ?? 1;
        $datasetSize = $context['dataset_size'] ?? 50;
        $modality = $context['modality'] ?? 'FULL';
        $prevAcc = $context['previous_accuracy'] ?? null;
        $gpu = $context['gpu_available'] ?? false;

        // Learning rate: decay with round number
        $lr = $round <= 3 ? 5e-4 : ($round <= 10 ? 1e-4 : 5e-5);

        // Adjust for dataset size
        if ($datasetSize < 50) {
            $lr *= 0.5;
        } elseif ($datasetSize > 200) {
            $lr *= 1.5;
        }

        // Weight decay: higher for small datasets
        $wd = $datasetSize < 50 ? 1e-3 : ($datasetSize < 200 ? 5e-4 : 1e-4);

        // Batch size
        $bs = $datasetSize < 50 ? 8 : ($datasetSize < 200 ? 16 : 32);
        if (!$gpu) {
            $bs = min($bs, 16);
        }

        // Local epochs
        $epochs = $round <= 3 ? 8 : ($round <= 10 ? 5 : 3);

        // Dropout
        $dropout = $datasetSize < 50 ? 0.6 : ($datasetSize < 200 ? 0.55 : 0.45);

        // DZ mode: more conservative
        if ($modality === 'DZ') {
            $lr *= 0.7;
            $dropout = min($dropout + 0.05, 0.7);
            $epochs = max($epochs - 1, 2);
        }

        // If previous accuracy was good, be more conservative
        if ($prevAcc !== null) {
            if ($prevAcc > 0.85) {
                $lr *= 0.5;
                $epochs = max($epochs - 1, 2);
            } elseif ($prevAcc < 0.6) {
                $lr *= 1.5;
                $epochs = min($epochs + 2, 10);
            }
        }

        return [
            'learning_rate' => $lr,
            'weight_decay'  => $wd,
            'batch_size'    => $bs,
            'local_epochs'  => $epochs,
            'dropout_rate'  => $dropout,
            'optimizer'     => 'AdamW',
            'scheduler'     => 'CosineAnnealingLR',
            'warmup_steps'  => max(1, (int)($datasetSize / $bs)),
            'reasoning'     => "Heuristic selection: round={$round}, samples={$datasetSize}, modality={$modality}, prev_acc={$prevAcc}",
        ];
    }
}
