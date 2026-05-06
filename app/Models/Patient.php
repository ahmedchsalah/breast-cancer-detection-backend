<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Patient extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'patient_identifier',
        'er_status',
        'pr_status',
        'her2_binary',
        'age',
        'stage_num',
        'er_status_missing',
        'pr_status_missing',
        'fraction_genome_altered',
        'buffa_hypoxia_score',
        'ragnum_hypoxia_score',
        'winter_hypoxia_score',
    ];

    protected $casts = [
        'er_status'               => 'boolean',
        'pr_status'               => 'boolean',
        'her2_binary'             => 'boolean',
        'er_status_missing'       => 'boolean',
        'pr_status_missing'       => 'boolean',
        'fraction_genome_altered' => 'float',
        'buffa_hypoxia_score'     => 'float',
        'ragnum_hypoxia_score'    => 'float',
        'winter_hypoxia_score'    => 'float',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function examinations(): HasMany
    {
        return $this->hasMany(Examination::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    public function wsiUploads(): HasMany
    {
        return $this->hasMany(WsiUpload::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
