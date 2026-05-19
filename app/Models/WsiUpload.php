<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WsiUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'uploaded_by',
        'organization_id',
        'file_path',
        'original_name',
        'file_size_bytes',
        'mime_type',
        'status',
        'r2_key',                 // Cloudflare R2 object key for the raw slide
        'features_path',          // path to pre-extracted CONCH .pt feature file (legacy)
        'features_extracted_at',  // when features were extracted
    ];

    protected $casts = [
        'features_extracted_at' => 'datetime',
    ];


    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function prediction(): HasOne
    {
        return $this->hasOne(Prediction::class);
    }
}
