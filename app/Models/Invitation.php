<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invitation extends Model
{
    use HasFactory; // ← was missing

    protected $fillable = [
        'email',
        'token',
        'organization_id',
        'role',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isValid(): bool
    {
        return $this->expires_at->isFuture();
    }
}
