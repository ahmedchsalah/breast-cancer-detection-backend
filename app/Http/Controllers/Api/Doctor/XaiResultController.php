<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Prediction;
use App\Models\XaiResult;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class XaiResultController extends Controller
{
    private function doctor()
    {
        return auth()->user();
    }

    // ============================================================
    //  SHOW
    // ============================================================

    #[OA\Get(
        path: "/doctor/predictions/{prediction_id}/xai",
        tags: ["Doctor — Predictions"],
        summary: "Get the XAI result for a given prediction",
        description: "Returns SHAP values, heatmap path, and top contributing features.",
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "prediction_id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "XAI details",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "prediction_id", type: "integer"),
                        new OA\Property(property: "is_lum_a", type: "boolean"),
                        new OA\Property(property: "confidence_lum_a", type: "number", format: "float"),
                        new OA\Property(property: "confidence_non_lum_a", type: "number", format: "float"),
                        new OA\Property(property: "xai", type: "object", properties: [
                            new OA\Property(property: "heatmap_path", type: "string", nullable: true),
                            new OA\Property(property: "heatmap_status", type: "string"),
                            new OA\Property(property: "shap_plot_path", type: "string", nullable: true),
                            new OA\Property(property: "shap_status", type: "string"),
                            new OA\Property(property: "shap_values", type: "object", nullable: true),
                            new OA\Property(property: "top_features", type: "object", nullable: true),
                        ]),
                    ]
                )
            ),
            new OA\Response(response: 403, description: "Not authorized"),
            new OA\Response(response: 404, description: "Not found / XAI not generated"),
            new OA\Response(response: 422, description: "Prediction not completed"),
        ]
    )]
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
