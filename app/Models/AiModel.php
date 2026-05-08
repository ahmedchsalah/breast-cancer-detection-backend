<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'version',
        'inference_type',
        'description',
        'auc',
        'accuracy',
        'sensitivity',
        'specificity',
        'f1_score',
        'n_checkpoints',
        'threshold',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'is_active'    => 'boolean',
        'auc'          => 'float',
        'accuracy'     => 'float',
        'sensitivity'  => 'float',
        'specificity'  => 'float',
        'f1_score'     => 'float',
        'threshold'    => 'float',
        'n_checkpoints'=> 'integer',
    ];

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    public function flRounds(): HasMany
    {
        return $this->hasMany(FlRound::class);
    }

    /**
     * Get the currently active A6 fusion model (default for new predictions).
     */
    public static function activeA6(): self
    {
        return static::where('inference_type', 'a6_fusion')
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * Get the active A4 image-only model.
     */
    public static function activeA4(): self
    {
        return static::where('inference_type', 'a4_image_only')
            ->where('is_active', true)
            ->firstOrFail();
    }
}
