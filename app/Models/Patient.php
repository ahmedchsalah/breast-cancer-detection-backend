<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Patient extends Model
{
    use HasFactory;

    /**
     * BRECAI-FED patient identifier format:
     * BRECAI-FED-XX-XXXX-XXXX
     *
     * Where:
     *   XX    = 2-letter org code (derived from org ID, zero-padded)
     *   XXXX  = 4-digit sequential number (per org)
     *   XXXX  = 4-digit random alphanumeric (for uniqueness)
     *
     * Example: BRECAI-FED-01-0001-A3F7
     */
    public const IDENTIFIER_PREFIX = 'BRECAI-FED';

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

    /**
     * Generate a unique BRECAI-FED patient identifier.
     *
     * Format: BRECAI-FED-{org_code}-{seq}-{random}
     * Example: BRECAI-FED-01-0001-A3F7
     */
    public static function generateIdentifier(int $organizationId): string
    {
        // Org code: 2-digit zero-padded from org ID
        $orgCode = str_pad($organizationId % 100, 2, '0', STR_PAD_LEFT);

        // Sequential number: next available for this org
        $lastSeq = static::where('organization_id', $organizationId)
            ->where('patient_identifier', 'like', self::IDENTIFIER_PREFIX . "-{$orgCode}-%")
            ->count();

        $seq = str_pad($lastSeq + 1, 4, '0', STR_PAD_LEFT);

        // Random suffix: 4 uppercase alphanumeric characters for uniqueness
        $random = strtoupper(Str::random(4));

        $identifier = self::IDENTIFIER_PREFIX . "-{$orgCode}-{$seq}-{$random}";

        // Ensure uniqueness (extremely unlikely collision, but handle it)
        $attempts = 0;
        while (static::where('patient_identifier', $identifier)->exists() && $attempts < 10) {
            $random = strtoupper(Str::random(4));
            $identifier = self::IDENTIFIER_PREFIX . "-{$orgCode}-{$seq}-{$random}";
            $attempts++;
        }

        return $identifier;
    }

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
