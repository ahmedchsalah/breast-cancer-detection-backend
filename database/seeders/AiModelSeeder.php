<?php

namespace Database\Seeders;

use App\Models\AiModel;
use Illuminate\Database\Seeder;

/**
 * AiModelSeeder
 *
 * Seeds the two BReCAI v12 models into the database.
 * Performance metrics come from the training/validation results.
 * The actual model weights live on HuggingFace — this is purely metadata.
 *
 * Models:
 *   A6 — Cross-Attention Fusion (CONCH image features + 19 clinical features)
 *   A4 — Attention MIL (image features only — no clinical data required)
 *
 * Run with: php artisan db:seed --class=AiModelSeeder
 */
class AiModelSeeder extends Seeder
{
    public function run(): void
    {
        // ── A6 Cross-Attention Fusion ──────────────────────────────────────────
        AiModel::updateOrCreate(
            ['slug' => 'a6_fusion'],
            [
                'name'           => 'BReCAI-A6 Cross-Attention Fusion',
                'version'        => 'v12.0',
                'inference_type' => 'a6_fusion',
                'description'    =>
                    'Full fusion model combining CONCH ViT-B/16 patch features with 19 '
                    . 'clinical biomarkers using Cross-Attention. Best accuracy. '
                    . 'Requires both a WSI feature file (.pt) and clinical data. '
                    . '15-checkpoint ensemble (3 seeds × 5-fold CV) + TTA×8.',
                // Performance metrics from v12 validation set
                'auc'            => 0.9210,
                'accuracy'       => 0.8750,
                'sensitivity'    => 0.9100, // LumA recall
                'specificity'    => 0.8400, // Non-LumA recall
                'f1_score'       => 0.8930,
                'n_checkpoints'  => 15,
                'threshold'      => 0.5100,
                'is_active'      => true,
                'metadata'       => [
                    'backbone'       => 'CONCH ViT-B/16',
                    'clinical_dim'   => 19,
                    'hidden_dim'     => 128,
                    'n_heads'        => 4,
                    'tta_runs'       => 8,
                    'max_patches'    => 1000,
                    'hf_repo'        => 'ahmedchikhsalah/brecai-v12-checkpoints',
                    'checkpoint_pattern' => 'A6_conch_s*_f*.pt',
                    'requires_wsi'   => true,
                    'requires_clinical' => true,
                ],
            ]
        );

        // ── A4 Attention MIL (image-only) ─────────────────────────────────────
        AiModel::updateOrCreate(
            ['slug' => 'a4_image_only'],
            [
                'name'           => 'BReCAI-A4 Attention MIL',
                'version'        => 'v12.0',
                'inference_type' => 'a4_image_only',
                'description'    =>
                    'Image-only Attention MIL model using CONCH ViT-B/16 patch features. '
                    . 'No clinical data required — useful when biomarker data is unavailable. '
                    . '15-checkpoint ensemble (3 seeds × 5-fold CV) + TTA×8.',
                // Performance metrics (image-only, slightly lower than A6)
                'auc'            => 0.8940,
                'accuracy'       => 0.8480,
                'sensitivity'    => 0.8700,
                'specificity'    => 0.8200,
                'f1_score'       => 0.8620,
                'n_checkpoints'  => 15,
                'threshold'      => 0.5100,
                'is_active'      => true,
                'metadata'       => [
                    'backbone'       => 'CONCH ViT-B/16',
                    'clinical_dim'   => null,
                    'hidden_dim'     => 128,
                    'n_heads'        => null,
                    'tta_runs'       => 8,
                    'max_patches'    => 1000,
                    'hf_repo'        => 'ahmedchikhsalah/brecai-v12-checkpoints',
                    'checkpoint_pattern' => 'A4_conch_s*_f*.pt',
                    'requires_wsi'   => true,
                    'requires_clinical' => false,
                ],
            ]
        );

        $this->command->info('✅ BReCAI v12 AI models seeded (A6 Fusion + A4 Image-Only).');
    }
}
