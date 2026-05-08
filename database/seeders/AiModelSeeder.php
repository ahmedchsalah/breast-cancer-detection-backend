<?php

namespace Database\Seeders;

use App\Models\AiModel;
use Illuminate\Database\Seeder;

class AiModelSeeder extends Seeder
{
    public function run(): void
    {
        // 1. BReCAI-A6 (The Flagship Fusion Model)
        AiModel::updateOrCreate(
            ['slug' => 'a6_fusion'],
            [
                'name' => 'BReCAI-A6 Cross-Attention Fusion',
                'version' => 'v12.5',
                'inference_type' => 'a6_fusion',
                'description' => 'Flagship fusion model combining CONCH ViT-B/16 patch features with 19 clinical biomarkers. Uses gated cross-attention to focus on the most relevant features. 15-checkpoint ensemble.',
                'metadata' => [
                    'backbone' => 'CONCH ViT-B/16',
                    'clinical_dim' => 19,
                    'hidden_dim' => 128,
                    'n_heads' => 4,
                    'tta_runs' => 8,
                    'max_patches' => 1000,
                    'hf_repo' => 'ahmedchikhsalah/brecai-v12-checkpoints',
                    'checkpoint_pattern' => 'A6_conch_s*_f*.pt',
                    'requires_wsi' => true,
                    'requires_clinical' => true,
                ],
                'is_active' => true,
                'auc' => 0.9016,
                'accuracy' => 0.852,
                'sensitivity' => 0.890,
                'specificity' => 0.814,
                'f1_score' => 0.860,
                'n_checkpoints' => 15,
                'threshold' => 0.51,
            ]
        );

        // 2. BReCAI-A4 (Image-Only CONCH)
        AiModel::updateOrCreate(
            ['slug' => 'a4_image_only'],
            [
                'name' => 'BReCAI-A4 Attention MIL (CONCH)',
                'version' => 'v12.4',
                'inference_type' => 'a4_image_only',
                'description' => 'Image-only Attention MIL model using CONCH ViT-B/16 features. Best used when clinical biomarkers are unavailable.',
                'metadata' => [
                    'backbone' => 'CONCH ViT-B/16',
                    'clinical_dim' => null,
                    'hidden_dim' => 128,
                    'n_heads' => null,
                    'tta_runs' => 8,
                    'max_patches' => 1000,
                    'hf_repo' => 'ahmedchikhsalah/brecai-v12-checkpoints',
                    'checkpoint_pattern' => 'A4_conch_s*_f*.pt',
                    'requires_wsi' => true,
                    'requires_clinical' => false,
                ],
                'is_active' => true,
                'auc' => 0.8004,
                'accuracy' => 0.735,
                'sensitivity' => 0.822,
                'specificity' => 0.649,
                'f1_score' => 0.761,
                'n_checkpoints' => 15,
                'threshold' => 0.51,
            ]
        );

        // 3. BReCAI-A1 (Clinical-Only Stacking)
        AiModel::updateOrCreate(
            ['slug' => 'a1_clinical'],
            [
                'name' => 'BReCAI-A1 Clinical Stacking',
                'version' => 'v12.3',
                'inference_type' => 'a1_clinical',
                'description' => 'Ensemble of LightGBM, CatBoost, XGBoost, and RF using clinical features only. Extremely robust performance based on tabular data alone.',
                'metadata' => [
                    'backbone' => 'Tabular Ensemble',
                    'clinical_dim' => 19,
                    'hidden_dim' => null,
                    'n_heads' => null,
                    'tta_runs' => 0,
                    'max_patches' => 0,
                    'hf_repo' => 'ahmedchikhsalah/brecai-v12-checkpoints',
                    'checkpoint_pattern' => 'A1_stack_s*_f*.pkl',
                    'requires_wsi' => false,
                    'requires_clinical' => true,
                ],
                'is_active' => true,
                'auc' => 0.9127,
                'accuracy' => 0.840,
                'sensitivity' => 0.847,
                'specificity' => 0.830,
                'f1_score' => 0.840,
                'n_checkpoints' => 20,
                'threshold' => 0.51,
            ]
        );
    }
}
