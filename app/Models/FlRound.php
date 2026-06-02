<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlRound extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_model_id',
        'round_number',
        'modality',
        'title',
        'description',
        'min_samples',
        'status',
        'global_accuracy',
        'global_loss',
        'started_at',
        'ended_at',
        'deadline',
        'aggregation_method',
        'recommended_hyperparams',
        'blockchain_receipt',
        'aggregated_weights_r2_key',
        'aggregated_weights_hash',
    ];

    protected $casts = [
        'global_accuracy'         => 'float',
        'global_loss'             => 'float',
        'started_at'              => 'datetime',
        'ended_at'                => 'datetime',
        'deadline'                => 'datetime',
        'recommended_hyperparams' => 'array',
        'blockchain_receipt'      => 'array',
    ];

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(FlContribution::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(FlRoundInvitation::class);
    }
}
