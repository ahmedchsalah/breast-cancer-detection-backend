<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invitation extends Model
{
    protected $fillable = [
        'email',
        'token',
        'organization_id',
        'role',
        'expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Check if the invitation is still valid.
     */
    public function isValid(): bool
    {
        return $this->expires_at->isFuture();
    }
}
