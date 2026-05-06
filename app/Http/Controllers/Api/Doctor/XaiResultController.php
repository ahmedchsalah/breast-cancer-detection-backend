<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Prediction;
use App\Models\XaiResult;
use Illuminate\Http\JsonResponse;

class XaiResultController extends Controller
{
    private function doctor()
    {
        return auth()->user();
    }

    /**
     * Get the XAI result for a given prediction.
     * Returns SHAP values, heatmap path, and top contributing features.
     */
    public function show(Prediction $prediction): JsonResponse
    {
        abort_if(
            $prediction->organization_id !== $this->doctor()->organization_id,
            403,
            'You do not have access to this prediction.'
        );

        if ($prediction->status !== Prediction::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'XAI results are only available for completed predictions.',
                'status'  => $prediction->status,
            ], 422);
        }

        $xai = $prediction->xaiResult;

        if (!$xai) {
            return response()->json([
                'message' => 'XAI results have not been generated yet for this prediction.',
            ], 404);
        }

        return response()->json([
            'prediction_id'  => $prediction->id,
            'is_lum_a'       => $prediction->is_lum_a,
            'confidence_lum_a'     => $prediction->confidence_lum_a,
            'confidence_non_lum_a' => $prediction->confidence_non_lum_a,
            'xai' => [
                'heatmap_path'  => $xai->heatmap_path,
                'heatmap_status'=> $xai->heatmap_status,
                'shap_plot_path'=> $xai->shap_plot_path,
                'shap_status'   => $xai->shap_status,
                'shap_values'   => $xai->shap_values,
                'top_features'  => $xai->top_features,
            ],
        ]);
    }
}
