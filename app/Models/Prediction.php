<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Prediction extends Model
{
    use HasFactory;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';

    protected $fillable = [
        'examination_id',
        'patient_id',
        'organization_id',
        'ai_model_id',
        'wsi_upload_id',
        'is_lum_a',
        'confidence_lum_a',
        'confidence_non_lum_a',
        'clinical_input_snapshot',
        'status',
        'job_id',
        'failure_reason',
        'completed_at',
    ];

    protected $casts = [
        'is_lum_a'                => 'boolean',
        'confidence_lum_a'        => 'float',
        'confidence_non_lum_a'    => 'float',
        'clinical_input_snapshot' => 'array',
        'completed_at'            => 'datetime',
    ];

    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    public function wsiUpload(): BelongsTo
    {
        return $this->belongsTo(WsiUpload::class);
    }

    public function xaiResult(): HasOne
    {
        return $this->hasOne(XaiResult::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(Report::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
