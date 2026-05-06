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
        'version',
        'file_path',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'metadata'  => 'array',
        'is_active' => 'boolean',
    ];

    public function flRounds(): HasMany
    {
        return $this->hasMany(FlRound::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }
}
