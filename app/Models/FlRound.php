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
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'global_accuracy' => 'float',
        'started_at'      => 'datetime',
        'ended_at'        => 'datetime',
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
