<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XaiResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'prediction_id',
        'heatmap_path',
        'heatmap_status',
        'shap_values',
        'shap_plot_path',
        'shap_status',
        'top_features',
    ];

    protected $casts = [
        'shap_values'  => 'array',
        'top_features' => 'array',
    ];

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }
}
