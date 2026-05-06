<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Examination extends Model
{
    use HasFactory;

    public const STATUS_DRAFT      = 'draft';
    public const STATUS_SUBMITTED  = 'submitted';
    public const STATUS_PREDICTED  = 'predicted';
    public const STATUS_CONCLUDED  = 'concluded';

    protected $fillable = [
        'patient_id',
        'doctor_id',
        'organization_id',
        'chief_complaint',
        'clinical_notes',
        'doctor_conclusion',
        'status',
        'examined_at',
    ];

    protected $casts = [
        'examined_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function prediction(): HasOne
    {
        return $this->hasOne(Prediction::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(Report::class);
    }
}
